<?php

declare(strict_types=1);

namespace Spark\Qr;

use GdImage;
use InvalidArgumentException;
use RuntimeException;
use function array_slice;
use function chr;
use function count;
use function is_array;
use function ord;
use function strlen;

/**
 * Pure-PHP QR code decoder. Raster inputs need GD; Shift-JIS conversion needs mbstring or iconv.
 *
 * Pipeline: image -> grayscale -> binarize (Otsu, then adaptive, each also inverted)
 *   -> finder-pattern search -> affine grid sampling (handles rotation/scale)
 *   -> format info (BCH) -> unmask -> de-interleave -> Reed-Solomon correction
 *   -> segment parsing (numeric, alphanumeric, byte, kanji, ECI).
 *
 * Limits: strong perspective distortion and curved surfaces are not handled;
 * SVG is not readable by GD (use decodeMatrix() or rasterize first).
 *
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 * @version 1.0.0
 */
final class Decoder
{
    private const MAX_DIMENSION = 1200;        // larger images are downscaled first
    private const MAX_PIXELS = 40_000_000;  // refuse decompression bombs
    private const MAX_FILE_SIZE = 20_000_000;
    private const TIME_BUDGET = 10.0;        // seconds per decodeImage() call
    private const ECC_BY_BITS = [1 => 'L', 0 => 'M', 3 => 'Q', 2 => 'H'];
    private const ALNUM = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:';

    private static array $exp = [];
    private static array $log = [];
    private static array $formatCodes = [];
    private static array $maskCache = [];
    private static float $deadline = 0.0;

    // =================================================================== API

    /**
     * @return array{text:string, version:int, ecc:string, mask:int, corrected:int, segments:string[]}
     * @throws RuntimeException when no readable code is found
     */
    public static function decodeFile(string $path): array
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new InvalidArgumentException('File path must be non-empty and contain no null bytes.');
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException("Cannot read file: {$path}");
        }
        if (filesize($path) > self::MAX_FILE_SIZE) {
            throw new RuntimeException('Image file is too large.');
        }
        $image = @file_get_contents($path, false, null, 0, self::MAX_FILE_SIZE + 1);
        if ($image === false) {
            throw new RuntimeException("Cannot read file: {$path}");
        }
        return self::decodeString($image);
    }

    /** @param string $image Raw bytes of a PNG/JPEG/GIF/WebP/BMP image */
    public static function decodeString(string $image): array
    {
        if (strlen($image) > self::MAX_FILE_SIZE) {
            throw new RuntimeException('Image file is too large.');
        }
        if (!function_exists('imagecreatefromstring')) {
            throw new RuntimeException('The GD extension is required to decode images.');
        }
        $info = @getimagesizefromstring($image);
        if ($info === false) {
            throw new RuntimeException('Unsupported or corrupt image.');
        }
        if ($info[0] * $info[1] > self::MAX_PIXELS) {
            throw new RuntimeException('Image dimensions are too large.');
        }
        $im = @imagecreatefromstring($image);
        if ($im === false) {
            throw new RuntimeException('Unsupported or corrupt image.');
        }
        return self::decodeImage($im);
    }

    public static function decodeImage(GdImage $im): array
    {
        self::$deadline = microtime(true) + self::TIME_BUDGET;

        $sourceWidth = imagesx($im);
        $sourceHeight = imagesy($im);
        if ($sourceWidth * $sourceHeight > self::MAX_PIXELS) {
            throw new RuntimeException('Image dimensions are too large.');
        }
        $scale = min(1.0, self::MAX_DIMENSION / max($sourceWidth, $sourceHeight));
        $w = max(1, (int) round($sourceWidth * $scale));
        $h = max(1, (int) round($sourceHeight * $scale));

        // Flatten directly at the working resolution, avoiding a second full-size image.
        $flat = imagecreatetruecolor($w, $h);
        imagefill($flat, 0, 0, 0xFFFFFF);
        if ($scale < 1.0) {
            imagecopyresampled($flat, $im, 0, 0, 0, 0, $w, $h, $sourceWidth, $sourceHeight);
        } else {
            imagecopy($flat, $im, 0, 0, 0, 0, $w, $h);
        }

        $res = self::decodeFlat($flat, self::MAX_DIMENSION);
        if ($res !== null) {
            return $res;
        }

        // Tiny modules (small images): enlarge, nearest-neighbour first (exact for digital
        // codes), then bicubic (better for soft/photographed ones)
        $big = max($w, $h);
        if ($big < 700) {
            $k = max(2, min(8, intdiv(1100, $big)));
            foreach ([IMG_NEAREST_NEIGHBOUR, IMG_BICUBIC] as $mode) {
                if (microtime(true) > self::$deadline) {
                    break;
                }
                $up = imagescale($flat, $w * $k, $h * $k, $mode);
                if ($up !== false && ($res = self::decodeFlat($up, 2000)) !== null) {
                    return $res;
                }
            }
        }

        throw new RuntimeException('No readable QR code found in the image.');
    }

    /** One full pass over an opaque truecolor image. */
    private static function decodeFlat(GdImage $im, int $maxDim): ?array
    {
        $w = imagesx($im);
        $h = imagesy($im);
        $big = max($w, $h);
        if ($big > $maxDim) {
            $scaled = imagescale(
                $im,
                max(1, (int) round($w * $maxDim / $big)),
                max(1, (int) round($h * $maxDim / $big)),
                IMG_BILINEAR_FIXED
            );
            if ($scaled !== false) {
                $im = $scaled;
                $w = imagesx($im);
                $h = imagesy($im);
            }
        }

        $gray = [];
        $hist = array_fill(0, 256, 0);
        for ($y = 0; $y < $h; $y++) {
            $row = [];
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($im, $x, $y);
                $row[] = ((($c >> 16) & 255) * 77 + (($c >> 8) & 255) * 150 + ($c & 255) * 29) >> 8;
            }
            foreach (array_count_values($row) as $g => $n) {
                $hist[$g] += $n;
            }
            $gray[] = pack('C*', ...$row);   // 1 byte/pixel instead of a 16-byte PHP int
        }

        $rows = self::threshold($gray, self::otsu($hist, $w * $h));
        $res = self::decodeBinary($rows, $w, $h)
            ?? (microtime(true) < self::$deadline ? self::decodeBinary(self::invert($rows), $w, $h) : null);
        if ($res !== null || microtime(true) > self::$deadline) {
            return $res;
        }

        // Uneven lighting: local-mean threshold
        $rows = self::adaptive($gray, $w, $h);
        unset($gray);
        return self::decodeBinary($rows, $w, $h)
            ?? (microtime(true) < self::$deadline ? self::decodeBinary(self::invert($rows), $w, $h) : null);
    }

    /**
     * Decode an upright, already-sampled module matrix ([y][x], 1/true = dark).
     *
     * @param array<int, array<int, int|bool>> $modules
     * @return array{text:string, version:int, ecc:string, mask:int, corrected:int, segments:string[]}
     */
    public static function decodeMatrix(array $modules): array
    {
        $n = count($modules);
        if ($n < 21 || $n > 177 || ($n - 17) % 4 !== 0) {
            throw new InvalidArgumentException('Matrix size is not a valid QR code size.');
        }
        $m = [];
        foreach (array_values($modules) as $row) {
            if (!is_array($row) || count($row) !== $n) {
                throw new InvalidArgumentException('Matrix must be square.');
            }
            $normalized = [];
            foreach ($row as $value) {
                if ($value !== 0 && $value !== 1 && !is_bool($value)) {
                    throw new InvalidArgumentException('Matrix modules must be 0, 1, false or true.');
                }
                $normalized[] = (int) $value;
            }
            $m[] = $normalized;
        }

        $version = intdiv($n - 17, 4);
        $data = self::readFormat($m, $n);
        if ($data === null) {
            throw new RuntimeException('Format information is unreadable.');
        }
        $ecc = self::ECC_BY_BITS[$data >> 3];
        $mask = $data & 7;

        $info = Encoder::blockInfo($version, $ecc);
        $cw = self::readCodewords($m, $n, self::fnMask($version), $mask, $info['rawCodewords']);

        $numBlocks = $info['blocks'];
        $eccLen = $info['eccLen'];
        $shortLen = intdiv($info['rawCodewords'], $numBlocks);
        $numShort = $numBlocks - $info['rawCodewords'] % $numBlocks;

        $dataLen = [];
        $dataBlk = [];
        $eccBlk = [];
        for ($b = 0; $b < $numBlocks; $b++) {
            $dataLen[$b] = $shortLen - $eccLen + ($b < $numShort ? 0 : 1);
            $dataBlk[$b] = [];
            $eccBlk[$b] = [];
        }
        $k = 0;
        for ($col = 0; $col < $shortLen - $eccLen + 1; $col++) {
            for ($b = 0; $b < $numBlocks; $b++) {
                if ($col < $dataLen[$b]) {
                    $dataBlk[$b][] = $cw[$k++];
                }
            }
        }
        for ($col = 0; $col < $eccLen; $col++) {
            for ($b = 0; $b < $numBlocks; $b++) {
                $eccBlk[$b][] = $cw[$k++];
            }
        }

        self::initGalois();
        $bytes = [];
        $corrected = 0;
        for ($b = 0; $b < $numBlocks; $b++) {
            $block = [...$dataBlk[$b], ...$eccBlk[$b]];
            $fixed = self::rsCorrect($block, $eccLen);
            if ($fixed === null) {
                throw new RuntimeException('Too many errors to correct (Reed-Solomon failed).');
            }
            $corrected += $fixed;
            array_push($bytes, ...array_slice($block, 0, $dataLen[$b]));
        }

        [$text, $segments] = self::parseSegments($bytes, $version);
        return [
            'text' => $text,
            'version' => $version,
            'ecc' => $ecc,
            'mask' => $mask,
            'corrected' => $corrected,
            'segments' => $segments,
        ];
    }

    // ===================================================== image preparation

    private static function otsu(array $hist, int $total): int
    {
        $sum = 0;
        foreach ($hist as $i => $c) {
            $sum += $i * $c;
        }
        $sumB = 0;
        $wB = 0;
        $max = -1.0;
        $thr = 127;
        for ($t = 0; $t < 256; $t++) {
            $wB += $hist[$t];
            if ($wB === 0) {
                continue;
            }
            $wF = $total - $wB;
            if ($wF === 0) {
                break;
            }
            $sumB += $t * $hist[$t];
            $d = $sumB / $wB - ($sum - $sumB) / $wF;
            $var = (float) $wB * $wF * $d * $d;
            if ($var > $max) {
                $max = $var;
                $thr = $t;
            }
        }
        return $thr;
    }

    /** @param string[] $gray packed rows @return string[] rows of '1' (dark) / '0' (light) */
    private static function threshold(array $gray, int $t): array
    {
        $from = '';
        $to = '';
        for ($g = 0; $g < 256; $g++) {
            $from .= chr($g);
            $to .= $g <= $t ? '1' : '0';
        }
        return array_map(static fn(string $row): string => strtr($row, $from, $to), $gray);
    }

    /** Local-mean threshold using an integral image (robust to shadows/gradients). */
    private static function adaptive(array $gray, int $w, int $h): array
    {
        $r = ((max(15, intdiv(min($w, $h), 8)) | 1) >> 1);

        // Integral image, one packed 'N*' string per row (4 bytes/cell instead of 16)
        $prev = array_fill(0, $w + 1, 0);
        $I = [pack('N*', ...$prev)];
        for ($y = 0; $y < $h; $y++) {
            $cur = [0];
            $acc = 0;
            foreach (array_values(unpack('C*', $gray[$y])) as $x => $g) {
                $acc += $g;
                $cur[] = $prev[$x + 1] + $acc;
            }
            $I[] = pack('N*', ...$cur);
            $prev = $cur;
        }
        unset($prev, $cur);

        $rows = [];
        for ($y = 0; $y < $h; $y++) {
            $y0 = max(0, $y - $r);
            $y1 = min($h, $y + $r + 1);
            $r0 = array_values(unpack('N*', $I[$y0]));
            $r1 = array_values(unpack('N*', $I[$y1]));
            $gr = array_values(unpack('C*', $gray[$y]));
            $s = '';
            for ($x = 0; $x < $w; $x++) {
                $x0 = max(0, $x - $r);
                $x1 = min($w, $x + $r + 1);
                $sum = $r1[$x1] - $r0[$x1] - $r1[$x0] + $r0[$x0];
                $area = ($y1 - $y0) * ($x1 - $x0);
                $s .= $gr[$x] * $area * 10 <= $sum * 9 ? '1' : '0';
            }
            $rows[] = $s;
        }
        return $rows;
    }

    private static function invert(array $rows): array
    {
        return array_map(static fn(string $s): string => strtr($s, '01', '10'), $rows);
    }

    // ============================================================== locating

    private static function decodeBinary(array $rows, int $w, int $h): ?array
    {
        $cands = self::findFinders($rows, $w, $h);
        if (count($cands) < 3) {
            return null;
        }
        $triples = self::bestTriples($cands);
        foreach ([true, false] as $strongOnly) {
            foreach ($triples as $tri) {
                if (microtime(true) > self::$deadline) {
                    return null;
                }
                $res = self::decodeTriple($rows, $w, $h, $tri, $strongOnly);
                if ($res !== null) {
                    return $res;
                }
            }
        }
        return null;
    }

    private static function ratioOk(int $a, int $b, int $c, int $d, int $e): bool
    {
        $total = $a + $b + $c + $d + $e;
        if ($total < 7) {
            return false;
        }
        $m = $total / 7;
        $tol = $m * 0.6 + 0.4;
        return abs($a - $m) <= $tol && abs($b - $m) <= $tol && abs($d - $m) <= $tol
            && abs($e - $m) <= $tol && abs($c - 3 * $m) <= 3 * $tol;
    }

    /** Five alternating runs (dark,light,DARK,light,dark) around $pos; null if $pos is not dark. */
    private static function fiveRuns(string $line, int $pos): ?array
    {
        $len = strlen($line);
        if ($pos < 0 || $pos >= $len || $line[$pos] !== '1') {
            return null;
        }
        $s = $pos;
        while ($s > 0 && $line[$s - 1] === '1') {
            $s--;
        }
        $e = $pos;
        while ($e + 1 < $len && $line[$e + 1] === '1') {
            $e++;
        }
        $c = $e - $s + 1;

        $i = $s - 1;
        $b = 0;
        while ($i >= 0 && $line[$i] === '0') {
            $b++;
            $i--;
        }
        if ($b === 0 || $i < 0) {
            return null;
        }
        $a = 0;
        while ($i >= 0 && $line[$i] === '1') {
            $a++;
            $i--;
        }
        $i = $e + 1;
        $d = 0;
        while ($i < $len && $line[$i] === '0') {
            $d++;
            $i++;
        }
        if ($d === 0 || $i >= $len) {
            return null;
        }
        $f = 0;
        while ($i < $len && $line[$i] === '1') {
            $f++;
            $i++;
        }
        return [$a, $b, $c, $d, $f, $s + $c / 2];
    }

    /** @return array<int, array{x:float,y:float,m:float,n:int}> */
    private static function findFinders(array $rows, int $w, int $h): array
    {
        $active = [];   // candidates that can still receive hits from the current row
        $done = [];
        $cols = [];
        for ($y = 0; $y < $h; $y++) {
            if (($y & 15) === 0 && microtime(true) > self::$deadline) {
                break;
            }
            foreach ($active as $k => $cd) {  // scanning downwards: old candidates are final
                if ($y > $cd['y'] + 5 * $cd['m'] + 1) {
                    $done[] = $cd;
                    unset($active[$k]);
                }
            }

            $row = $rows[$y];
            if (!preg_match_all('/1+|0+/', $row, $mm, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $runs = $mm[0];
            $cnt = count($runs);
            for ($i = ($runs[0][0][0] === '1' ? 0 : 1); $i + 4 < $cnt; $i += 2) {
                $a = strlen($runs[$i][0]);
                $b = strlen($runs[$i + 1][0]);
                $c = strlen($runs[$i + 2][0]);
                $d = strlen($runs[$i + 3][0]);
                $e = strlen($runs[$i + 4][0]);
                if (!self::ratioOk($a, $b, $c, $d, $e)) {
                    continue;
                }
                $m = ($a + $b + $c + $d + $e) / 7;
                $xc = $runs[$i + 2][1] + $c / 2;

                // Same finder as one already confirmed? just count the hit.
                $merged = false;
                foreach ($active as $k => $cd) {
                    if (abs($cd['x'] - $xc) <= 2 * $m + 1 && abs($cd['y'] - $y) <= 5 * $m + 1) {
                        $active[$k]['n']++;
                        $merged = true;
                        break;
                    }
                }
                if ($merged || count($active) + count($done) > 3000) {
                    continue;
                }

                // Cross-check vertically, then refine x on the true centre row
                $xi = (int) floor($xc);
                if (!isset($cols[$xi])) {
                    $col = '';
                    for ($yy = 0; $yy < $h; $yy++) {
                        $col .= $rows[$yy][$xi];
                    }
                    $cols[$xi] = $col;
                }
                $v = self::fiveRuns($cols[$xi], $y);
                if ($v === null || !self::ratioOk($v[0], $v[1], $v[2], $v[3], $v[4])) {
                    continue;
                }
                $yc = $v[5];
                $mv = ($v[0] + $v[1] + $v[2] + $v[3] + $v[4]) / 7;
                $hz = self::fiveRuns($rows[min($h - 1, (int) floor($yc))], $xi);
                if ($hz !== null && self::ratioOk($hz[0], $hz[1], $hz[2], $hz[3], $hz[4])) {
                    $xc = $hz[5];
                    $m = ($hz[0] + $hz[1] + $hz[2] + $hz[3] + $hz[4]) / 7;
                }
                $mAvg = ($m + $mv) / 2;
                if ($mAvg >= 3 && !self::diagonalsOk($rows, $w, $h, $xc, $yc, $mAvg)) {
                    continue; // coincidental 1:1:3:1:1 in the data area
                }
                $active[] = ['x' => (float) $xc, 'y' => (float) $yc, 'm' => $mAvg, 'n' => 1];
            }
        }
        return array_merge($done, array_values($active));
    }

    /** A true finder keeps its 1:1:3:1:1 ratio along both diagonals through its centre. */
    private static function diagonalsOk(array $rows, int $w, int $h, float $xc, float $yc, float $m): bool
    {
        $r = (int) ceil(4 * $m) + 2;
        $cx = (int) floor($xc);
        $cy = (int) floor($yc);
        foreach ([1, -1] as $dir) {
            $s = '';
            for ($t = -$r; $t <= $r; $t++) {
                $x = $cx + $t;
                $y = $cy + $dir * $t;
                $s .= ($x >= 0 && $y >= 0 && $x < $w && $y < $h) ? $rows[$y][$x] : '0';
            }
            $v = self::fiveRuns($s, $r);
            if ($v === null || !self::ratioOk($v[0], $v[1], $v[2], $v[3], $v[4])) {
                return false;
            }
        }
        return true;
    }

    /** Best right-isosceles triples of finder candidates: [corner(TL), A, B, moduleSize]. */
    private static function bestTriples(array $c): array
    {
        usort($c, static fn($a, $b) => $b['n'] <=> $a['n']);
        $c = array_slice($c, 0, 20);
        $k = count($c);
        $out = [];
        for ($i = 0; $i < $k - 2; $i++) {
            for ($j = $i + 1; $j < $k - 1; $j++) {
                for ($l = $j + 1; $l < $k; $l++) {
                    $P = [$c[$i], $c[$j], $c[$l]];
                    $ms = array_column($P, 'm');
                    if (max($ms) / max(0.5, min($ms)) > 1.6) {
                        continue;
                    }
                    $d01 = hypot($P[0]['x'] - $P[1]['x'], $P[0]['y'] - $P[1]['y']);
                    $d12 = hypot($P[1]['x'] - $P[2]['x'], $P[1]['y'] - $P[2]['y']);
                    $d02 = hypot($P[0]['x'] - $P[2]['x'], $P[0]['y'] - $P[2]['y']);
                    // corner = vertex opposite the longest side
                    $max = max($d01, $d12, $d02);
                    $ci = $max === $d12 ? 0 : ($max === $d02 ? 1 : 2);
                    $o = array_values(array_diff([0, 1, 2], [$ci]));
                    $K = $P[$ci];
                    $A = $P[$o[0]];
                    $B = $P[$o[1]];
                    $l1 = hypot($A['x'] - $K['x'], $A['y'] - $K['y']);
                    $l2 = hypot($B['x'] - $K['x'], $B['y'] - $K['y']);
                    $mean = array_sum($ms) / 3;
                    if (min($l1, $l2) < 8 * $mean) {
                        continue;
                    }
                    $skew = abs($l1 - $l2) / max($l1, $l2);
                    $cos = abs(($A['x'] - $K['x']) * ($B['x'] - $K['x']) + ($A['y'] - $K['y']) * ($B['y'] - $K['y'])) / ($l1 * $l2);
                    if ($skew > 0.3 || $cos > 0.3) {
                        continue;
                    }
                    $out[] = ['score' => $skew + $cos, 'pts' => [$K, $A, $B], 'm' => $mean];
                }
            }
        }
        usort($out, static fn($a, $b) => $a['score'] <=> $b['score']);
        return array_slice($out, 0, 8);
    }

    /**
     * Two passes over the ranked triples: first only those whose timing-pattern count agrees
     * with their size (cheap, almost never a false positive), then every plausible version.
     */
    private static function decodeTriple(array $rows, int $w, int $h, array $tri, bool $strongOnly): ?array
    {
        [$tl, $a, $b] = $tri['pts'];
        // image y points down: TR is the one that makes cross(TR-TL, BL-TL) > 0
        $cross = ($a['x'] - $tl['x']) * ($b['y'] - $tl['y']) - ($a['y'] - $tl['y']) * ($b['x'] - $tl['x']);
        [$tr, $bl] = $cross > 0 ? [$a, $b] : [$b, $a];

        $cands = self::versionCandidates($rows, $w, $h, $tl, $tr, $bl, $tri['m']);
        foreach ($strongOnly ? $cands['strong'] : $cands['all'] as $v) {
            if (microtime(true) > self::$deadline) {
                return null;
            }
            $n = 4 * $v + 17;
            $mat = self::sampleGrid($rows, $w, $h, $tl, $tr, $bl, $n);
            if ($mat === null) {
                continue;
            }
            foreach ([$mat, array_map(null, ...$mat)] as $try) { // normal, then mirrored
                try {
                    return self::decodeMatrix($try);
                } catch (RuntimeException) {
                    // try next
                }
            }
        }
        return null;
    }

    /** Candidate versions: timing-pattern count first, then size-based estimate +/-1. */
    private static function versionCandidates(array $rows, int $w, int $h, array $tl, array $tr, array $bl, float $m): array
    {
        $dx = $tr['x'] - $tl['x'];
        $dy = $tr['y'] - $tl['y'];
        $dist = hypot($dx, $dy);

        // finder runs are stretched by 1/cos(rotation); undo that
        $ang = atan2($dy, $dx);
        $a = fmod($ang + M_PI / 4, M_PI / 2);
        if ($a < 0) {
            $a += M_PI / 2;
        }
        $a -= M_PI / 4;
        $mt = $m * cos($a);
        $vSize = (int) round(($dist / $mt + 7 - 17) / 4);
        $list = [];

        // Timing pattern: count light/dark transitions along module row 6
        $ux = $dx / $dist;
        $uy = $dy / $dist;
        $bx = $bl['x'] - $tl['x'];
        $by = $bl['y'] - $tl['y'];
        $bd = hypot($bx, $by);
        $px = $bx / $bd * 3 * $mt;
        $py = $by / $bd * 3 * $mt;
        $step = max(0.25 * $mt, 0.5);
        $steps = (int) floor($dist / $step);
        $state = null;
        $pend = 0;
        $trans = 0;
        for ($i = 0; $i <= $steps; $i++) {
            $x = (int) floor($tl['x'] + $px + $ux * $i * $step);
            $y = (int) floor($tl['y'] + $py + $uy * $i * $step);
            if ($x < 0 || $y < 0 || $x >= $w || $y >= $h) {
                $trans = -1;
                break;
            }
            $bit = $rows[$y][$x];
            if ($state === null) {
                $state = $bit;
            } elseif ($bit === $state) {
                $pend = 0;
            } elseif (++$pend >= 2) {
                $state = $bit;
                $trans++;
                $pend = 0;
            }
        }
        $valid = static fn(int $v): bool => $v >= 1 && $v <= 40;
        $strong = [];
        if ($trans >= 4 && ($trans - 4) % 4 === 0) {
            $vt = intdiv($trans - 4, 4);
            if ($valid($vt)) {
                $list[] = $vt;
                if (abs($vt - $vSize) <= 2) {
                    $strong[] = $vt;
                }
            }
        }
        foreach ([$vSize, $vSize + 1, $vSize - 1] as $v) {
            $list[] = $v;
        }
        return [
            'strong' => $strong,
            'all' => array_values(array_unique(array_filter($list, $valid))),
        ];
    }

    /** Affine sampling with a 5-point vote per module. */
    private static function sampleGrid(array $rows, int $w, int $h, array $tl, array $tr, array $bl, int $n): ?array
    {
        $d = $n - 7;
        $ex = [($tr['x'] - $tl['x']) / $d, ($tr['y'] - $tl['y']) / $d];
        $ey = [($bl['x'] - $tl['x']) / $d, ($bl['y'] - $tl['y']) / $d];
        $o = 0.3;
        $offs = [[0, 0], [-$o, 0], [$o, 0], [0, -$o], [0, $o]];

        $mat = [];
        for ($j = 0; $j < $n; $j++) {
            $row = [];
            for ($i = 0; $i < $n; $i++) {
                $u = $i - 3.0;
                $v = $j - 3.0;
                $cx = $tl['x'] + $u * $ex[0] + $v * $ey[0];
                $cy = $tl['y'] + $u * $ex[1] + $v * $ey[1];
                $dark = 0;
                foreach ($offs as [$a, $b]) {
                    $x = (int) floor($cx + $a * $ex[0] + $b * $ey[0]);
                    $y = (int) floor($cy + $a * $ex[1] + $b * $ey[1]);
                    if ($x < 0 || $y < 0 || $x >= $w || $y >= $h) {
                        return null;
                    }
                    if ($rows[$y][$x] === '1') {
                        $dark++;
                    }
                }
                $row[] = $dark >= 3 ? 1 : 0;
            }
            $mat[] = $row;
        }
        return $mat;
    }

    // ============================================================ matrix side

    private static function fnMask(int $version): array
    {
        return self::$maskCache[$version] ??= Encoder::functionMask($version);
    }

    private static function maskBit(int $mask, int $x, int $y): bool
    {
        return match ($mask) {
            0 => ($x + $y) % 2 === 0,
            1 => $y % 2 === 0,
            2 => $x % 3 === 0,
            3 => ($x + $y) % 3 === 0,
            4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
            5 => $x * $y % 2 + $x * $y % 3 === 0,
            6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
            7 => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
        };
    }

    private static function readFormat(array $m, int $n): ?int
    {
        if (self::$formatCodes === []) {
            for ($d = 0; $d < 32; $d++) {
                $rem = $d;
                for ($i = 0; $i < 10; $i++) {
                    $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
                }
                self::$formatCodes[$d] = (($d << 10) | $rem) ^ 0x5412;
            }
        }

        $a = 0;
        $b = 0;
        for ($i = 0; $i < 15; $i++) {
            [$x1, $y1] = match (true) {
                $i <= 5 => [8, $i],
                $i === 6 => [8, 7],
                $i === 7 => [8, 8],
                $i === 8 => [7, 8],
                default => [14 - $i, 8],
            };
            [$x2, $y2] = $i < 8 ? [$n - 1 - $i, 8] : [8, $n - 15 + $i];
            $a |= $m[$y1][$x1] << $i;
            $b |= $m[$y2][$x2] << $i;
        }

        $best = null;
        $bd = 99;
        foreach ([$a, $b] as $bits) {
            foreach (self::$formatCodes as $d => $code) {
                $dist = substr_count(decbin($bits ^ $code), '1');
                if ($dist < $bd) {
                    $bd = $dist;
                    $best = $d;
                }
            }
        }
        return $bd <= 3 ? $best : null;
    }

    /** @return int[] */
    private static function readCodewords(array $m, int $n, array $fn, int $mask, int $count): array
    {
        $out = array_fill(0, $count, 0);
        $total = $count * 8;
        $bit = 0;
        for ($right = $n - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            $up = (($right + 1) & 2) === 0;
            for ($vert = 0; $vert < $n; $vert++) {
                $y = $up ? $n - 1 - $vert : $vert;
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    if (!$fn[$y][$x] && $bit < $total) {
                        $v = $m[$y][$x] ^ (self::maskBit($mask, $x, $y) ? 1 : 0);
                        if ($v) {
                            $out[$bit >> 3] |= 0x80 >> ($bit & 7);
                        }
                        $bit++;
                    }
                }
            }
        }
        return $out;
    }

    // ============================================================ Reed-Solomon

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

    private static function mul(int $a, int $b): int
    {
        return ($a === 0 || $b === 0) ? 0 : self::$exp[self::$log[$a] + self::$log[$b]];
    }

    private static function div(int $a, int $b): int
    {
        return $a === 0 ? 0 : self::$exp[self::$log[$a] + 255 - self::$log[$b]];
    }

    /** @return int[] */
    private static function syndromes(array $cw, int $nsym): array
    {
        $S = [];
        for ($j = 0; $j < $nsym; $j++) {
            $aj = self::$exp[$j];
            $s = 0;
            foreach ($cw as $c) {
                $s = self::mul($s, $aj) ^ $c;
            }
            $S[] = $s;
        }
        return $S;
    }

    /**
     * Berlekamp-Massey + Chien search + Forney. Corrects $cw in place.
     * @return int|null number of corrected codewords, null if uncorrectable
     */
    private static function rsCorrect(array &$cw, int $nsym): ?int
    {
        $S = self::syndromes($cw, $nsym);
        if (!array_filter($S)) {
            return 0;
        }

        // Error-locator polynomial (coefficients ascending)
        $C = [1];
        $B = [1];
        $L = 0;
        $m = 1;
        $b = 1;
        for ($n = 0; $n < $nsym; $n++) {
            $d = $S[$n];
            for ($i = 1; $i <= $L; $i++) {
                if (isset($C[$i])) {
                    $d ^= self::mul($C[$i], $S[$n - $i]);
                }
            }
            if ($d === 0) {
                $m++;
                continue;
            }
            $T = $C;
            $coef = self::div($d, $b);
            while (count($C) < count($B) + $m) {
                $C[] = 0;
            }
            foreach ($B as $i => $bi) {
                $C[$i + $m] ^= self::mul($coef, $bi);
            }
            if (2 * $L <= $n) {
                $L = $n + 1 - $L;
                $B = $T;
                $b = $d;
                $m = 1;
            } else {
                $m++;
            }
        }
        if ($L > intdiv($nsym, 2)) {
            return null;
        }
        $C = array_slice($C, 0, $L + 1);
        $C = array_pad($C, $L + 1, 0);

        // Chien search: roots of Lambda are alpha^-p for error power p
        $len = count($cw);
        $pos = [];
        for ($p = 0; $p < $len; $p++) {
            $x = self::$exp[(255 - $p) % 255];
            $val = 0;
            for ($i = $L; $i >= 0; $i--) {
                $val = self::mul($val, $x) ^ $C[$i];
            }
            if ($val === 0) {
                $pos[] = $p;
            }
        }
        if (count($pos) !== $L) {
            return null;
        }

        // Omega = S * Lambda mod x^nsym
        $omega = [];
        for ($k = 0; $k < $nsym; $k++) {
            $acc = 0;
            for ($i = 0; $i <= $k && $i <= $L; $i++) {
                $acc ^= self::mul($C[$i], $S[$k - $i]);
            }
            $omega[] = $acc;
        }

        foreach ($pos as $p) {
            $xinv = self::$exp[(255 - $p) % 255];
            $X = self::$exp[$p % 255];

            $ov = 0;
            for ($i = $nsym - 1; $i >= 0; $i--) {
                $ov = self::mul($ov, $xinv) ^ $omega[$i];
            }
            // formal derivative of Lambda (odd terms only, in GF(2^m))
            $dv = 0;
            for ($i = 1; $i <= $L; $i += 2) {
                $dv ^= self::mul($C[$i], $i === 1 ? 1 : self::$exp[(self::$log[$xinv] * ($i - 1)) % 255]);
            }
            if ($dv === 0) {
                return null;
            }
            $cw[$len - 1 - $p] ^= self::mul($X, self::div($ov, $dv));
        }

        return array_filter(self::syndromes($cw, $nsym)) ? null : $L;
    }

    // ============================================================ segments

    /** @return array{0:string, 1:string[]} */
    private static function parseSegments(array $bytes, int $version): array
    {
        $bits = implode('', array_map(static fn(int $b): string => sprintf('%08b', $b), $bytes));
        $total = strlen($bits);
        $pos = 0;
        $take = static function (int $n) use (&$pos, $bits, $total): int {
            if ($pos + $n > $total) {
                throw new RuntimeException('Data stream is truncated.');
            }
            $v = bindec(substr($bits, $pos, $n));
            $pos += $n;
            return (int) $v;
        };
        $ci = $version <= 9 ? 0 : ($version <= 26 ? 1 : 2);

        $out = '';
        $segments = [];
        $eci = null;
        while ($pos + 4 <= $total) {
            $mode = $take(4);
            if ($mode === 0) {
                break;
            }
            switch ($mode) {
                case 1: // numeric
                    $count = $take([10, 12, 14][$ci]);
                    while ($count >= 3) {
                        $v = $take(10);
                        if ($v > 999) {
                            throw new RuntimeException('Invalid numeric data.');
                        }
                        $out .= sprintf('%03d', $v);
                        $count -= 3;
                    }
                    if ($count === 2) {
                        $v = $take(7);
                        if ($v > 99) {
                            throw new RuntimeException('Invalid numeric data.');
                        }
                        $out .= sprintf('%02d', $v);
                    } elseif ($count === 1) {
                        $v = $take(4);
                        if ($v > 9) {
                            throw new RuntimeException('Invalid numeric data.');
                        }
                        $out .= (string) $v;
                    }
                    $segments[] = 'numeric';
                    break;

                case 2: // alphanumeric
                    $count = $take([9, 11, 13][$ci]);
                    while ($count >= 2) {
                        $v = $take(11);
                        if ($v >= 45 * 45) {
                            throw new RuntimeException('Invalid alphanumeric data.');
                        }
                        $out .= self::ALNUM[intdiv($v, 45)] . self::ALNUM[$v % 45];
                        $count -= 2;
                    }
                    if ($count === 1) {
                        $v = $take(6);
                        if ($v >= 45) {
                            throw new RuntimeException('Invalid alphanumeric data.');
                        }
                        $out .= self::ALNUM[$v];
                    }
                    $segments[] = 'alnum';
                    break;

                case 4: // byte
                    $count = $take([8, 16, 16][$ci]);
                    $raw = '';
                    for ($i = 0; $i < $count; $i++) {
                        $raw .= chr($take(8));
                    }
                    $out .= self::byteSegmentToUtf8($raw, $eci);
                    $segments[] = 'byte';
                    break;

                case 8: // kanji
                    $count = $take([8, 10, 12][$ci]);
                    $sjis = '';
                    for ($i = 0; $i < $count; $i++) {
                        $v = $take(13);
                        $cc = intdiv($v, 0xC0) << 8 | $v % 0xC0;
                        $cc += $cc < 0x1F00 ? 0x8140 : 0xC140;
                        $sjis .= chr($cc >> 8) . chr($cc & 0xFF);
                    }
                    $out .= self::convert($sjis, 'SJIS');
                    $segments[] = 'kanji';
                    break;

                case 7: // ECI
                    $first = $take(1);
                    if ($first === 0) {
                        $eci = $take(7);
                    } elseif ($take(1) === 0) {
                        $eci = $take(14);
                    } else {
                        if ($take(1) !== 0) {
                            throw new RuntimeException('Invalid ECI header.');
                        }
                        $eci = $take(21);
                    }
                    if ($eci > 999999) {
                        throw new RuntimeException('Invalid ECI assignment.');
                    }
                    if (!in_array($eci, [3, 20, 26], true)) {
                        throw new RuntimeException("Unsupported ECI assignment {$eci}.");
                    }
                    break;

                case 3: // structured append header
                case 5: // FNC1 (first position)
                case 9: // FNC1 (second position)
                    throw new RuntimeException('Structured append and FNC1 are not supported.');

                default:
                    throw new RuntimeException("Unsupported segment mode {$mode}.");
            }
        }
        return [$out, $segments];
    }

    private static function byteSegmentToUtf8(string $raw, ?int $eci): string
    {
        if ($eci === 26 && preg_match('//u', $raw) !== 1) {
            throw new RuntimeException('Invalid UTF-8 byte segment.');
        }
        return match (true) {
            $eci === 3 => self::convert($raw, 'ISO-8859-1'),
            $eci === 20 => self::convert($raw, 'SJIS'),
            $eci === null && preg_match('//u', $raw) !== 1 => self::convert($raw, 'ISO-8859-1'), // not valid UTF-8
            default => $raw,
        };
    }

    private static function convert(string $s, string $from): string
    {
        if ($from === 'ISO-8859-1') {
            $o = '';
            for ($i = 0, $l = strlen($s); $i < $l; $i++) {
                $c = ord($s[$i]);
                $o .= $c < 0x80 ? $s[$i] : chr(0xC0 | $c >> 6) . chr(0x80 | $c & 0x3F);
            }
            return $o;
        }
        if (function_exists('mb_convert_encoding')) {
            if (!mb_check_encoding($s, $from)) {
                throw new RuntimeException("Invalid {$from} data.");
            }
            return mb_convert_encoding($s, 'UTF-8', $from);
        }
        if (function_exists('iconv')) {
            $r = @iconv($from, 'UTF-8', $s);
            if ($r !== false) {
                return $r;
            }
            throw new RuntimeException("Invalid {$from} data.");
        }
        throw new RuntimeException("Decoding {$from} data requires the mbstring or iconv extension.");
    }
}
