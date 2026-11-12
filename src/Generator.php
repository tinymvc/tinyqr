<?php

declare(strict_types=1);

namespace Spark\Qr;

use InvalidArgumentException;
use RuntimeException;
use function in_array;
use function ord;
use function strlen;

/**
 * Generates QR codes in PNG or SVG format, with no external dependencies.
 * 
 * This class is designed to be a self-contained QR code generator that can produce QR codes in either 
 * PNG or SVG format. It allows for customization of various parameters such as size, error correction 
 * level, margin, colors, and format. The generated QR codes can be saved to a file, streamed 
 * directly to the browser, or returned as a Base64 data URI for use in HTML.
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 * @version 1.0.0
 */
class Generator
{
    private string $data;
    private int $size;
    private string $ecc;
    private int $margin;
    private string $foregroundColor;
    private string $backgroundColor;
    private string $format;
    private bool $transparent = false;
    private bool $boostEcc = true;
    private int $mask = -1;

    /** Cached encoder output; depends only on data / ecc / boost / mask. */
    private ?array $matrix = null;

    private const VALID_ECC = ['L', 'M', 'Q', 'H'];
    private const VALID_FORMATS = ['png', 'svg'];

    /**
     * @param string $data   The text or URL to encode
     * @param int    $size   Target width/height in pixels (default: 300)
     * @param string $ecc    Error Correction Level: L (7%), M (15%), Q (25%), H (30%)
     * @param int    $margin Quiet zone around the code, in modules (default: 4)
     */
    public function __construct(
        string $data,
        int $size = 300,
        string $ecc = 'M',
        int $margin = 4
    ) {
        $this->setData($data);
        $this->setSize($size);
        $this->setErrorCorrection($ecc);
        $this->setMargin($margin);
        $this->foregroundColor = '000000';
        $this->backgroundColor = 'ffffff';
        $this->format = 'png';
    }

    /**
     * Factory method for creating a new Generator instance.
     *
     * @param string $data   The text or URL to encode
     * @param int    $size   Target width/height in pixels (default: 300)
     * @param string $ecc    Error Correction Level: L (7%), M (15%), Q (25%), H (30%)
     * @param int    $margin Quiet zone around the code, in modules (default: 4)
     *
     * @return self
     */
    public static function create(string $data, int $size = 300, string $ecc = 'M', int $margin = 4): self
    {
        return new self($data, $size, $ecc, $margin);
    }

    public function setData(string $data): self
    {
        if ($data === '') {
            throw new InvalidArgumentException('QR code data cannot be empty.');
        }
        $this->data = $data;
        $this->matrix = null;
        return $this;
    }

    public function setSize(int $size): self
    {
        if ($size < 50 || $size > 1000) {
            throw new InvalidArgumentException('Size must be between 50 and 1000 pixels.');
        }
        $this->size = $size;
        return $this;
    }

    public function setErrorCorrection(string $ecc): self
    {
        $ecc = strtoupper($ecc);
        if (!in_array($ecc, self::VALID_ECC, true)) {
            throw new InvalidArgumentException('Invalid ECC level. Allowed: L, M, Q, H.');
        }
        $this->ecc = $ecc;
        $this->matrix = null;
        return $this;
    }

    public function setMargin(int $margin): self
    {
        if ($margin < 0 || $margin > 50) {
            throw new InvalidArgumentException('Margin must be between 0 and 50.');
        }
        $this->margin = $margin;
        return $this;
    }

    public function setColors(string $foregroundHex, string $backgroundHex = 'ffffff'): self
    {
        $fg = strtolower(str_starts_with($foregroundHex, '#') ? substr($foregroundHex, 1) : $foregroundHex);
        $bg = strtolower(str_starts_with($backgroundHex, '#') ? substr($backgroundHex, 1) : $backgroundHex);

        if (!preg_match('/^[0-9a-f]{6}$/D', $fg) || !preg_match('/^[0-9a-f]{6}$/D', $bg)) {
            throw new InvalidArgumentException('Colors must be valid 6-character hex codes.');
        }
        if (self::contrastRatio($fg, $bg) < 3.0) {
            throw new InvalidArgumentException(
                'Foreground/background contrast is too low (need at least 3:1) for reliable scanning.'
            );
        }

        $this->foregroundColor = $fg;
        $this->backgroundColor = $bg;
        return $this;
    }

    public function setFormat(string $format): self
    {
        $format = strtolower($format);
        if (!in_array($format, self::VALID_FORMATS, true)) {
            throw new InvalidArgumentException('Format must be either "png" or "svg".');
        }
        $this->format = $format;
        return $this;
    }

    /** Transparent background (no background fill in SVG, alpha palette entry in PNG). */
    public function setTransparentBackground(bool $transparent = true): self
    {
        $this->transparent = $transparent;
        return $this;
    }

    /** Automatically raise the ECC level when it fits in the same version (free robustness). */
    public function setBoostErrorCorrection(bool $boost): self
    {
        $this->boostEcc = $boost;
        $this->matrix = null;
        return $this;
    }

    /** Force a mask pattern 0-7, or -1 for automatic best-mask selection. */
    public function setMask(int $mask): self
    {
        if ($mask < -1 || $mask > 7) {
            throw new InvalidArgumentException('Mask must be -1 (auto) or 0-7.');
        }
        $this->mask = $mask;
        $this->matrix = null;
        return $this;
    }

    /**
     * Encodes (once, then cached) and returns version, ecc, mask, size and the module matrix.
     *
     * @return array{version:int, ecc:string, mask:int, size:int, mode:string, modules:int[][]}
     */
    public function getMatrix(): array
    {
        return $this->matrix ??= Encoder::encode($this->data, $this->ecc, $this->boostEcc, $this->mask);
    }

    /**
     * Generates the QR code locally (no network calls) and returns the raw PNG/SVG payload.
     */
    public function getRawData(): string
    {
        return $this->format === 'svg' ? $this->renderSvg() : $this->renderPng();
    }

    /** Returns SVG without changing the configured output format. */
    public function getSvg(): string
    {
        return $this->renderSvg();
    }

    /** Returns PNG without changing the configured output format. */
    public function getPng(): string
    {
        return $this->renderPng();
    }

    public function getMimeType(): string
    {
        return $this->format === 'svg' ? 'image/svg+xml' : 'image/png';
    }

    /**
     * Returns a Base64 Data URI string ready for an HTML image source attribute
     */
    public function getBase64DataUri(): string
    {
        $mime = $this->getMimeType();
        return "data:$mime;base64," . base64_encode($this->getRawData());
    }

    /**
     * Saves the generated QR code directly to a local file path
     */
    public function saveToFile(string $filePath): bool
    {
        if ($filePath === '' || str_contains($filePath, "\0")) {
            throw new InvalidArgumentException('File path must be non-empty and contain no null bytes.');
        }
        $dir = dirname($filePath);
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new RuntimeException("Directory is not writable: {$dir}");
        }

        $raw = $this->getRawData();
        if (@file_put_contents($filePath, $raw, LOCK_EX) !== strlen($raw)) {
            throw new RuntimeException("Could not write QR code to: {$filePath}");
        }
        return true;
    }

    /**
     * Streams the QR code image directly to the browser (with ETag / 304 support)
     */
    public function renderToBrowser(): void
    {
        if (headers_sent()) {
            throw new RuntimeException('Headers already sent; cannot output the QR code.');
        }

        $raw = $this->getRawData();
        $mime = $this->getMimeType();
        $etag = '"' . md5($raw) . '"';

        header("ETag: $etag");
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('X-Content-Type-Options: nosniff');

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'GET' || $method === 'HEAD') {
            $validators = explode(',', $_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
            foreach ($validators as $validator) {
                $validator = trim($validator);
                if ($validator === '*' || $validator === $etag || $validator === 'W/' . $etag) {
                    http_response_code(304);
                    return;
                }
            }
        }

        http_response_code(200);
        header("Content-Type: $mime");
        header('Content-Length: ' . strlen($raw));
        if ($method !== 'HEAD') {
            echo $raw;
        }
    }

    // ------------------------------------------------------------------
    // Payload helpers
    // ------------------------------------------------------------------

    /** Builds a WiFi join payload: WIFI:T:WPA;S:name;P:pass;H:true;; */
    public static function wifiPayload(string $ssid, string $password = '', string $auth = 'WPA', bool $hidden = false): string
    {
        if ($ssid === '') {
            throw new InvalidArgumentException('WiFi SSID cannot be empty.');
        }
        $auth = strtoupper($auth);
        if (!in_array($auth, ['WPA', 'WEP', 'NOPASS'], true)) {
            throw new InvalidArgumentException('Auth must be WPA, WEP or nopass.');
        }
        $esc = static fn(string $v): string => preg_replace('/([\\\\;,:"])/', '\\\\$1', $v);

        $out = 'WIFI:T:' . ($auth === 'NOPASS' ? 'nopass' : $auth) . ';S:' . $esc($ssid) . ';';
        if ($auth !== 'NOPASS') {
            $out .= 'P:' . $esc($password) . ';';
        }
        if ($hidden) {
            $out .= 'H:true;';
        }
        return $out . ';';
    }

    /** Builds an authenticator provisioning URI from an existing Base32 secret. */
    public static function totpPayload(
        string $secret,
        string $account,
        string $issuer,
        string $algorithm = 'SHA1',
        int $digits = 6,
        int $period = 30
    ): string {
        $secret = strtoupper($secret);
        if (preg_match('/^([A-Z2-7]+)(={0,6})$/D', $secret, $matches) !== 1) {
            throw new InvalidArgumentException('Secret must be non-empty Base32 with optional trailing padding.');
        }
        $secret = $matches[1];
        $remainder = strlen($secret) % 8;
        $padding = strlen($matches[2]);
        $unusedBits = [0 => 0, 2 => 2, 4 => 4, 5 => 1, 7 => 3][$remainder] ?? null;
        if ($unusedBits === null || ($padding > 0 && $padding !== 8 - $remainder)) {
            throw new InvalidArgumentException('Secret has an invalid Base32 length or padding.');
        }
        // A partial Base32 group must encode whole bytes with zero unused bits.
        $lastValue = strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', $secret[strlen($secret) - 1]);
        if (($lastValue & ((1 << $unusedBits) - 1)) !== 0) {
            throw new InvalidArgumentException('Secret has non-zero Base32 padding bits.');
        }

        foreach (['Account' => $account, 'Issuer' => $issuer] as $name => $label) {
            if (
                preg_match('//u', $label) !== 1
                || preg_match('/\A[\s\p{Z}]*\z/u', $label) === 1
                || preg_match('/[:\p{Cc}]/u', $label) === 1
            ) {
                throw new InvalidArgumentException("{$name} must be non-blank UTF-8 without colons or control characters.");
            }
        }
        $algorithm = strtoupper($algorithm);
        if (!in_array($algorithm, ['SHA1', 'SHA256', 'SHA512'], true)) {
            throw new InvalidArgumentException('Algorithm must be SHA1, SHA256 or SHA512.');
        }
        if (!in_array($digits, [6, 8], true)) {
            throw new InvalidArgumentException('Digits must be 6 or 8.');
        }
        if ($period < 1) {
            throw new InvalidArgumentException('Period must be a positive number of seconds.');
        }

        $query = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => $algorithm,
            'digits' => $digits,
            'period' => $period,
        ], '', '&', PHP_QUERY_RFC3986);
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account) . '?' . $query;
    }

    public static function emailPayload(string $email, string $subject = '', string $body = ''): string
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Invalid email address.');
        }
        $query = [];
        if ($subject !== '') {
            $query['subject'] = $subject;
        }
        if ($body !== '') {
            $query['body'] = $body;
        }
        $uri = 'mailto:' . str_replace('%40', '@', rawurlencode($email));
        return $query === [] ? $uri : $uri . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    public static function phonePayload(string $number): string
    {
        return 'tel:' . self::normalizePhoneNumber($number);
    }

    public static function smsPayload(string $number, string $message = ''): string
    {
        $uri = 'sms:' . self::normalizePhoneNumber($number);
        return $message === '' ? $uri : $uri . '?body=' . rawurlencode($message);
    }

    public static function geoPayload(float $latitude, float $longitude): string
    {
        if (
            !is_finite($latitude) || !is_finite($longitude)
            || abs($latitude) > 90 || abs($longitude) > 180
        ) {
            throw new InvalidArgumentException('Latitude must be between -90 and 90; longitude between -180 and 180.');
        }
        $format = static fn(float $value): string => rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
        return 'geo:' . $format($latitude) . ',' . $format($longitude);
    }

    /** Builds a vCard 3.0 contact with escaped text and CRLF line endings. */
    public static function vCardPayload(
        string $firstName,
        string $lastName = '',
        string $phone = '',
        string $email = '',
        string $organization = '',
        string $url = ''
    ): string {
        if (trim($firstName . $lastName) === '') {
            throw new InvalidArgumentException('Contact name cannot be empty.');
        }
        $escape = static fn(string $value): string => strtr($value, [
            '\\' => '\\\\',
            "\r\n" => '\\n',
            "\r" => '\\n',
            "\n" => '\\n',
            ';' => '\\;',
            ',' => '\\,',
        ]);
        $lines = [
            'BEGIN:VCARD',
            'VERSION:3.0',
            'N:' . $escape($lastName) . ';' . $escape($firstName) . ';;;',
            'FN:' . $escape(trim("$firstName $lastName")),
        ];
        if ($phone !== '') {
            $lines[] = 'TEL:' . self::normalizePhoneNumber($phone);
        }
        if ($email !== '') {
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidArgumentException('Invalid email address.');
            }
            $lines[] = 'EMAIL:' . $escape($email);
        }
        if ($organization !== '') {
            $lines[] = 'ORG:' . $escape($organization);
        }
        if ($url !== '') {
            if (preg_match('/[\x00-\x20\x7f]/', $url) === 1 || filter_var($url, FILTER_VALIDATE_URL) === false) {
                throw new InvalidArgumentException('Invalid contact URL.');
            }
            $lines[] = "URL:$url";
        }
        $lines[] = 'END:VCARD';
        return implode("\r\n", $lines) . "\r\n";
    }

    private static function normalizePhoneNumber(string $number): string
    {
        $number = str_replace([' ', '-', '.', '(', ')'], '', $number);
        if (preg_match('/^\+?[0-9]+$/D', $number) !== 1) {
            throw new InvalidArgumentException('Phone number must contain digits and an optional leading +.');
        }
        return $number;
    }

    // ------------------------------------------------------------------
    // Renderers
    // ------------------------------------------------------------------

    private function renderSvg(): string
    {
        $qr = $this->getMatrix();
        $n = $qr['size'];
        $m = $this->margin;
        $total = $n + 2 * $m;

        $path = '';
        foreach ($qr['modules'] as $y => $row) {
            if (preg_match_all('/1+/', implode('', $row), $hits, PREG_OFFSET_CAPTURE)) {
                foreach ($hits[0] as [$run, $x]) {
                    $w = strlen($run);
                    $path .= 'M' . ($x + $m) . ' ' . ($y + $m) . 'h' . $w . 'v1h-' . $w . 'z';
                }
            }
        }

        $svg = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $svg .= '<svg xmlns="http://www.w3.org/2000/svg" width="' . $this->size . '" height="' . $this->size
            . '" viewBox="0 0 ' . $total . ' ' . $total . '" shape-rendering="crispEdges">';
        if (!$this->transparent) {
            $svg .= '<rect width="' . $total . '" height="' . $total . '" fill="#' . $this->backgroundColor . '"/>';
        }
        return $svg . '<path fill="#' . $this->foregroundColor . '" d="' . $path . '"/></svg>';
    }

    /**
     * Dependency-free PNG writer: 1-bit palette image, so files are tiny and need no GD.
     * Modules are scaled by an integer factor (always crisp) and centred on a canvas of
     * exactly the requested size; extra space is filled with the background colour.
     */
    private function renderPng(): string
    {
        $qr = $this->getMatrix();
        $n = $qr['size'];
        $total = $n + 2 * $this->margin;
        if ($this->size < $total) {
            throw new InvalidArgumentException("PNG size must be at least {$total} pixels for this QR code and margin.");
        }
        $scale = intdiv($this->size, $total);
        $inner = $total * $scale;
        $px = $this->size;

        $offset = intdiv($px - $inner, 2);
        $left = $offset + $this->margin * $scale;
        $right = $px - $left - $n * $scale;
        $top = $left;
        $bottom = $px - $top - $n * $scale;
        $rowLen = intdiv($px + 7, 8);

        $bgRow = "\0" . str_repeat("\0", $rowLen);
        $map = ['1' => str_repeat('1', $scale), '0' => str_repeat('0', $scale)];

        // 8-bit string -> byte lookup (PHP's pack() has no bit-string format)
        $lut = [];
        for ($i = 0; $i < 256; $i++) {
            $lut[sprintf('%08b', $i)] = chr($i);
        }

        $raw = str_repeat($bgRow, $top);
        foreach ($qr['modules'] as $row) {
            $bits = str_repeat('0', $left) . strtr(implode('', $row), $map) . str_repeat('0', $right);
            $bits = str_pad($bits, $rowLen * 8, '0');
            $raw .= str_repeat("\0" . strtr($bits, $lut), $scale);
        }
        $raw .= str_repeat($bgRow, $bottom);

        $png = "\x89PNG\r\n\x1a\n";
        $png .= self::pngChunk('IHDR', pack('NNCCCCC', $px, $px, 1, 3, 0, 0, 0));
        $png .= self::pngChunk('PLTE', hex2bin($this->backgroundColor) . hex2bin($this->foregroundColor));
        if ($this->transparent) {
            $png .= self::pngChunk('tRNS', "\0");
        }
        $png .= self::pngChunk('IDAT', gzcompress($raw, 9));
        return $png . self::pngChunk('IEND', '');
    }

    private static function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }

    // ------------------------------------------------------------------

    private static function contrastRatio(string $hexA, string $hexB): float
    {
        $lum = static function (string $hex): float {
            $c = array_map(static function (string $b): float {
                $v = ord($b) / 255;
                return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
            }, str_split(hex2bin($hex)));
            return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
        };
        $a = $lum($hexA);
        $b = $lum($hexB);
        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }
}
