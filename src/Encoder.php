<?php

declare(strict_types=1);

namespace Spark\Qr;

use InvalidArgumentException;
use LengthException;
use function array_slice;
use function count;
use function strlen;

/**
 * Pure-PHP QR Code encoder (ISO/IEC 18004), versions 1-40.
 *
 * - Numeric / alphanumeric / byte modes, auto-selected for the smallest output
 * - Error correction L/M/Q/H (optionally "boosted" for free when it fits)
 * - Smallest version that fits, automatic best-mask selection
 *
 * Returns a module matrix; rendering is done by Generator.
 *
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 * @version 1.0.0
 */
final class Encoder
{
    private const ECC_INDEX = ['L' => 0, 'M' => 1, 'Q' => 2, 'H' => 3];
    private const ECC_FORMAT_BITS = ['L' => 1, 'M' => 0, 'Q' => 3, 'H' => 2];
    private const ALNUM_CHARS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:';

    // Index = version (index 0 unused).
    private const ECC_CODEWORDS_PER_BLOCK = [
        [-1, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28, 28, 28, 30, 30, 26, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28],
        [-1, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30, 28, 30, 30, 30, 30, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        [-1, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28, 30, 24, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
    ];

    private const NUM_ERROR_CORRECTION_BLOCKS = [
        [-1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8, 8, 9, 9, 10, 12, 12, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 21, 22, 24, 25],
        [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49],
        [-1, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20, 23, 23, 25, 27, 29, 34, 34, 35, 38, 40, 43, 45, 48, 51, 53, 56, 59, 62, 65, 68],
        [-1, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25, 25, 34, 30, 32, 35, 37, 40, 42, 45, 48, 51, 54, 57, 60, 63, 66, 70, 74, 77, 81],
    ];

    /** @var int[] GF(256) tables (exp is doubled so no modulo is needed) */
    private static array $exp = [];
    private static array $log = [];
    /** @var array<int, int[]> cached Reed-Solomon generator polynomials */
    private static array $divisors = [];

    private int $size;
    /** @var int[][] 0/1 modules, [y][x] */
    private array $modules;
    /** @var bool[][] function-pattern map */
    private array $isFn;

    private function __construct(private int $version, private string $ecc)
    {
        $this->size = $version * 4 + 17;
        $row = array_fill(0, $this->size, 0);
        $this->modules = array_fill(0, $this->size, $row);
        $this->isFn = array_fill(0, $this->size, array_fill(0, $this->size, false));
    }

    /**
     * @param string $ecc       L, M, Q or H (minimum level)
     * @param bool   $boostEcc  Raise ECC level when it fits in the same version at no cost
     * @param int    $mask      0-7 to force a mask, -1 for automatic
     * @return array{version:int, ecc:string, mask:int, size:int, mode:string, modules:int[][]}
     */
    public static function encode(string $data, string $ecc = 'M', bool $boostEcc = true, int $mask = -1): array
    {
        $ecc = strtoupper($ecc);
        if (!isset(self::ECC_INDEX[$ecc])) {
            throw new InvalidArgumentException('Invalid ECC level.');
        }
        if ($mask < -1 || $mask > 7) {
            throw new InvalidArgumentException('Mask must be -1 (auto) or 0-7.');
        }
        if ($data === '') {
            throw new InvalidArgumentException('Data cannot be empty.');
        }
        if (strlen($data) > 7089) {
            throw new LengthException('Data exceeds the maximum QR code capacity of 7089 numeric characters.');
        }

        $mode = self::detectMode($data);
        $count = strlen($data);
        $payLen = match ($mode) {
            'numeric' => intdiv($count, 3) * 10 + [0, 4, 7][$count % 3],
            'alnum' => intdiv($count, 2) * 11 + ($count % 2) * 6,
            'byte' => $count * 8,
        };
        // Declare UTF-8 for non-ASCII text; arbitrary binary bytes remain unchanged.
        $utf8 = $mode === 'byte' && preg_match('/[\x80-\xff]/', $data) === 1
            && preg_match('//u', $data) === 1;
        $eciBits = $utf8 ? 12 : 0;
        $eccIdx = self::ECC_INDEX[$ecc];

        // Smallest version that fits
        for ($version = 1; ; $version++) {
            if ($version > 40) {
                throw new LengthException('Data is too long for a QR code at this error-correction level.');
            }
            $ccBits = self::countBits($mode, $version);
            if ($count >= (1 << $ccBits)) {
                continue;
            }
            if ($eciBits + 4 + $ccBits + $payLen <= self::numDataCodewords($version, $eccIdx) * 8) {
                break;
            }
        }

        // Free ECC upgrade
        if ($boostEcc) {
            foreach (['M', 'Q', 'H'] as $candidate) {
                $ci = self::ECC_INDEX[$candidate];
                if ($ci > $eccIdx && $eciBits + 4 + $ccBits + $payLen <= self::numDataCodewords($version, $ci) * 8) {
                    $ecc = $candidate;
                    $eccIdx = $ci;
                }
            }
        }

        // Bit stream -> data codewords
        self::initGalois();
        $capBytes = self::numDataCodewords($version, $eccIdx);
        $bits = $utf8 ? '011100011010' : ''; // ECI assignment 26 (UTF-8)
        $bits .= ['numeric' => '0001', 'alnum' => '0010', 'byte' => '0100'][$mode];
        $bits .= str_pad(decbin($count), $ccBits, '0', STR_PAD_LEFT) . self::encodePayload($data, $mode);
        $bits .= str_repeat('0', min(4, $capBytes * 8 - strlen($bits)));
        $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);

        $bytes = array_map('bindec', str_split($bits, 8));
        for ($pad = 0xEC; count($bytes) < $capBytes; $pad ^= 0xEC ^ 0x11) {
            $bytes[] = $pad;
        }

        $enc = new self($version, $ecc);
        $enc->drawFunctionPatterns();
        $enc->drawCodewords(self::addEccAndInterleave($bytes, $version, $eccIdx));

        if ($mask === -1) {
            $best = PHP_INT_MAX;
            for ($m = 0; $m < 8; $m++) {
                $enc->applyMask($m);
                $enc->drawFormatBits($m);
                $p = $enc->penalty();
                if ($p < $best) {
                    $best = $p;
                    $mask = $m;
                }
                $enc->applyMask($m); // XOR undo
            }
        }
        $enc->applyMask($mask);
        $enc->drawFormatBits($mask);

        return [
            'version' => $version,
            'ecc' => $ecc,
            'mask' => $mask,
            'size' => $enc->size,
            'mode' => $mode,
            'modules' => $enc->modules,
        ];
    }

    // ------------------------------------------------- helpers for the decoder

    /** @return bool[][] Function-pattern map (true = not a data module). */
    public static function functionMask(int $version): array
    {
        if ($version < 1 || $version > 40) {
            throw new InvalidArgumentException('Version must be between 1 and 40.');
        }
        $encoder = new self($version, 'L');
        $encoder->drawFunctionPatterns();
        return $encoder->isFn;
    }

    /** @return array{blocks:int, eccLen:int, rawCodewords:int} */
    public static function blockInfo(int $version, string $ecc): array
    {
        if ($version < 1 || $version > 40) {
            throw new InvalidArgumentException('Version must be between 1 and 40.');
        }
        $ecc = strtoupper($ecc);
        if (!isset(self::ECC_INDEX[$ecc])) {
            throw new InvalidArgumentException('Invalid ECC level.');
        }
        $index = self::ECC_INDEX[$ecc];
        return [
            'blocks' => self::NUM_ERROR_CORRECTION_BLOCKS[$index][$version],
            'eccLen' => self::ECC_CODEWORDS_PER_BLOCK[$index][$version],
            'rawCodewords' => intdiv(self::numRawDataModules($version), 8),
        ];
    }

    // ---------------------------------------------------------------- segments

    private static function detectMode(string $data): string
    {
        if (preg_match('/^[0-9]+$/D', $data) === 1) {
            return 'numeric';
        }
        if (preg_match('/^[0-9A-Z $%*+\-.\/:]+$/D', $data) === 1) {
            return 'alnum';
        }
        return 'byte';
    }

    private static function countBits(string $mode, int $version): int
    {
        $i = $version <= 9 ? 0 : ($version <= 26 ? 1 : 2);
        return [
            'numeric' => [10, 12, 14],
            'alnum' => [9, 11, 13],
            'byte' => [8, 16, 16],
        ][$mode][$i];
    }

    private static function encodePayload(string $data, string $mode): string
    {
        if ($mode === 'byte') {
            return implode('', array_map(
                static fn(int $b): string => sprintf('%08b', $b),
                unpack('C*', $data)
            ));
        }

        $out = [];
        if ($mode === 'numeric') {
            $width = [1 => 4, 2 => 7, 3 => 10];
            foreach (str_split($data, 3) as $chunk) {
                $out[] = str_pad(decbin((int) $chunk), $width[strlen($chunk)], '0', STR_PAD_LEFT);
            }
            return implode('', $out);
        }

        $map = array_flip(str_split(self::ALNUM_CHARS));
        foreach (str_split($data, 2) as $chunk) {
            if (strlen($chunk) === 2) {
                $out[] = str_pad(decbin($map[$chunk[0]] * 45 + $map[$chunk[1]]), 11, '0', STR_PAD_LEFT);
            } else {
                $out[] = str_pad(decbin($map[$chunk]), 6, '0', STR_PAD_LEFT);
            }
        }
        return implode('', $out);
    }

    // -------------------------------------------------------- capacity helpers

    private static function numRawDataModules(int $ver): int
    {
        $result = (16 * $ver + 128) * $ver + 64;
        if ($ver >= 2) {
            $numAlign = intdiv($ver, 7) + 2;
            $result -= (25 * $numAlign - 10) * $numAlign - 55;
            if ($ver >= 7) {
                $result -= 36;
            }
        }
        return $result;
    }

    private static function numDataCodewords(int $ver, int $eccIdx): int
    {
        return intdiv(self::numRawDataModules($ver), 8)
            - self::ECC_CODEWORDS_PER_BLOCK[$eccIdx][$ver] * self::NUM_ERROR_CORRECTION_BLOCKS[$eccIdx][$ver];
    }

    // ------------------------------------------------------------ Reed-Solomon

    private static function initGalois(): void
    {
        if (self::$exp !== []) {
            return;
        }
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$exp[$i] = $x;
            self::$log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }
        for ($i = 255; $i < 512; $i++) {
            self::$exp[$i] = self::$exp[$i - 255];
        }
    }

    private static function gfMul(int $x, int $y): int
    {
        return ($x === 0 || $y === 0) ? 0 : self::$exp[self::$log[$x] + self::$log[$y]];
    }

    /** @return int[] */
    private static function divisor(int $degree): array
    {
        if (isset(self::$divisors[$degree])) {
            return self::$divisors[$degree];
        }
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = self::gfMul($result[$j], $root);
                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }
            $root = self::gfMul($root, 2);
        }
        return self::$divisors[$degree] = $result;
    }

    /** @param int[] $data @param int[] $divisor @return int[] */
    private static function remainder(array $data, array $divisor): array
    {
        $result = array_fill(0, count($divisor), 0);
        foreach ($data as $b) {
            $factor = $b ^ array_shift($result);
            $result[] = 0;
            if ($factor !== 0) {
                $lf = self::$log[$factor];
                foreach ($divisor as $i => $c) {
                    if ($c !== 0) {
                        $result[$i] ^= self::$exp[self::$log[$c] + $lf];
                    }
                }
            }
        }
        return $result;
    }

    /** @param int[] $data @return int[] */
    private static function addEccAndInterleave(array $data, int $ver, int $eccIdx): array
    {
        $numBlocks = self::NUM_ERROR_CORRECTION_BLOCKS[$eccIdx][$ver];
        $blockEccLen = self::ECC_CODEWORDS_PER_BLOCK[$eccIdx][$ver];
        $rawCw = intdiv(self::numRawDataModules($ver), 8);
        $numShort = $numBlocks - $rawCw % $numBlocks;
        $shortLen = intdiv($rawCw, $numBlocks);
        $divisor = self::divisor($blockEccLen);

        $blocks = [];
        $k = 0;
        for ($i = 0; $i < $numBlocks; $i++) {
            $len = $shortLen - $blockEccLen + ($i < $numShort ? 0 : 1);
            $dat = array_slice($data, $k, $len);
            $k += $len;
            $ecc = self::remainder($dat, $divisor);
            if ($i < $numShort) {
                $dat[] = 0; // placeholder, skipped when interleaving
            }
            $blocks[] = [...$dat, ...$ecc];
        }

        $result = [];
        $blockLen = count($blocks[0]);
        for ($i = 0; $i < $blockLen; $i++) {
            foreach ($blocks as $j => $block) {
                if ($i !== $shortLen - $blockEccLen || $j >= $numShort) {
                    $result[] = $block[$i];
                }
            }
        }
        return $result;
    }

    // --------------------------------------------------------------- drawing

    private function setFn(int $x, int $y, bool $dark): void
    {
        $this->modules[$y][$x] = $dark ? 1 : 0;
        $this->isFn[$y][$x] = true;
    }

    private function drawFunctionPatterns(): void
    {
        $n = $this->size;
        for ($i = 0; $i < $n; $i++) {
            $this->setFn(6, $i, $i % 2 === 0);
            $this->setFn($i, 6, $i % 2 === 0);
        }
        $this->drawFinder(3, 3);
        $this->drawFinder($n - 4, 3);
        $this->drawFinder(3, $n - 4);

        $pos = $this->alignmentPositions();
        $last = count($pos) - 1;
        foreach ($pos as $i => $px) {
            foreach ($pos as $j => $py) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $last) || ($i === $last && $j === 0)) {
                    continue;
                }
                $this->drawAlignment($px, $py);
            }
        }

        $this->drawFormatBits(0); // reserve area
        $this->drawVersion();
    }

    private function drawFinder(int $cx, int $cy): void
    {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $x = $cx + $dx;
                $y = $cy + $dy;
                if ($x >= 0 && $x < $this->size && $y >= 0 && $y < $this->size) {
                    $dist = max(abs($dx), abs($dy));
                    $this->setFn($x, $y, $dist !== 2 && $dist !== 4);
                }
            }
        }
    }

    private function drawAlignment(int $cx, int $cy): void
    {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $this->setFn($cx + $dx, $cy + $dy, max(abs($dx), abs($dy)) !== 1);
            }
        }
    }

    /** @return int[] */
    private function alignmentPositions(): array
    {
        $ver = $this->version;
        if ($ver === 1) {
            return [];
        }
        $numAlign = intdiv($ver, 7) + 2;
        $step = $ver === 32 ? 26 : intdiv($ver * 4 + $numAlign * 2 + 1, $numAlign * 2 - 2) * 2;
        $result = [6];
        for ($i = $numAlign - 1, $pos = $this->size - 7; $i >= 1; $i--, $pos -= $step) {
            $result[$i] = $pos;
        }
        ksort($result);
        return array_values($result);
    }

    private function drawFormatBits(int $mask): void
    {
        $n = $this->size;
        $data = (self::ECC_FORMAT_BITS[$this->ecc] << 3) | $mask;
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = (($data << 10) | $rem) ^ 0x5412;
        $bit = static fn(int $i): bool => (($bits >> $i) & 1) !== 0;

        for ($i = 0; $i <= 5; $i++) {
            $this->setFn(8, $i, $bit($i));
        }
        $this->setFn(8, 7, $bit(6));
        $this->setFn(8, 8, $bit(7));
        $this->setFn(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $this->setFn(14 - $i, 8, $bit($i));
        }

        for ($i = 0; $i < 8; $i++) {
            $this->setFn($n - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->setFn(8, $n - 15 + $i, $bit($i));
        }
        $this->setFn(8, $n - 8, true); // always-dark module
    }

    private function drawVersion(): void
    {
        if ($this->version < 7) {
            return;
        }
        $rem = $this->version;
        for ($i = 0; $i < 12; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
        }
        $bits = ($this->version << 12) | $rem;
        for ($i = 0; $i < 18; $i++) {
            $dark = (($bits >> $i) & 1) !== 0;
            $a = $this->size - 11 + $i % 3;
            $b = intdiv($i, 3);
            $this->setFn($a, $b, $dark);
            $this->setFn($b, $a, $dark);
        }
    }

    /** @param int[] $codewords */
    private function drawCodewords(array $codewords): void
    {
        $n = $this->size;
        $total = count($codewords) * 8;
        $i = 0;
        for ($right = $n - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            $upward = (($right + 1) & 2) === 0;
            for ($vert = 0; $vert < $n; $vert++) {
                $y = $upward ? $n - 1 - $vert : $vert;
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    if (!$this->isFn[$y][$x] && $i < $total) {
                        $this->modules[$y][$x] = ($codewords[$i >> 3] >> (7 - ($i & 7))) & 1;
                        $i++;
                    }
                }
            }
        }
    }

    private function applyMask(int $mask): void
    {
        $n = $this->size;
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($this->isFn[$y][$x]) {
                    continue;
                }
                $invert = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => $x * $y % 2 + $x * $y % 3 === 0,
                    6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                    7 => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
                };
                if ($invert) {
                    $this->modules[$y][$x] ^= 1;
                }
            }
        }
    }

    /** Standard 4-rule mask penalty, string/regex based for speed. */
    private function penalty(): int
    {
        $n = $this->size;
        $score = 0;
        $lines = [];
        foreach ($this->modules as $row) {
            $lines[] = implode('', $row);
        }
        for ($x = 0; $x < $n; $x++) {
            $lines[] = implode('', array_column($this->modules, $x));
        }

        foreach ($lines as $s) {
            // Rule 1: runs of 5+ same-colour modules
            if (preg_match_all('/(.)\1{4,}/', $s, $m)) {
                foreach ($m[0] as $run) {
                    $score += strlen($run) - 2;
                }
            }
            // Rule 3: count overlapping and scaled 1:1:3:1:1 patterns, including the light border.
            $border = str_repeat('0', $n);
            preg_match_all('/0+|1+/', $border . $s . $border, $matches);
            $runs = array_map('strlen', $matches[0]);
            $runCount = count($runs);
            for ($i = 1; $i + 5 < $runCount; $i += 2) {
                $width = $runs[$i];
                if (
                    $runs[$i + 1] !== $width || $runs[$i + 2] !== 3 * $width
                    || $runs[$i + 3] !== $width || $runs[$i + 4] !== $width
                ) {
                    continue;
                }
                $before = $runs[$i - 1];
                $after = $runs[$i + 5];
                if ($before >= 4 * $width && $after >= $width) {
                    $score += 40;
                }
                if ($after >= 4 * $width && $before >= $width) {
                    $score += 40;
                }
            }
        }

        // Rule 2: 2x2 blocks
        for ($y = 0; $y < $n - 1; $y++) {
            $a = $this->modules[$y];
            $b = $this->modules[$y + 1];
            for ($x = 0; $x < $n - 1; $x++) {
                $c = $a[$x];
                if ($c === $a[$x + 1] && $c === $b[$x] && $c === $b[$x + 1]) {
                    $score += 3;
                }
            }
        }

        // Rule 4: dark/light balance
        $dark = 0;
        foreach ($this->modules as $row) {
            $dark += array_sum($row);
        }
        $total = $n * $n;
        $k = intdiv(abs($dark * 20 - $total * 10) + $total - 1, $total) - 1;
        $score += max(0, $k) * 10;

        return $score;
    }
}
