<?php

declare(strict_types=1);

use Spark\Qr\Decoder;
use Spark\Qr\Encoder;
use Spark\Qr\Generator;

$reference = json_decode(file_get_contents(__DIR__ . '/fixtures/reference.json'), true, flags: JSON_THROW_ON_ERROR);
foreach ($reference['vectors'] as $vector) {
    if ($vector['name'] === 'binary non-UTF8') {
        continue; // Text decoding of arbitrary bytes is covered separately below.
    }
    test('Decoder reference: ' . $vector['name'], static function () use ($vector): void {
        $data = str_repeat(base64_decode($vector['data'], true), $vector['repeat']);
        $qr = Encoder::encode($data, $vector['requestedEcc'], $vector['boost'], $vector['requestedMask']);
        same($vector['hash'], hash('sha256', implode('', array_map(static fn(array $row): string => implode('', $row), $qr['modules']))));
        $decoded = Decoder::decodeMatrix($qr['modules']);
        same($data, $decoded['text']);
        same($vector['version'], $decoded['version']);
        same($vector['ecc'], $decoded['ecc']);
        same($vector['mask'], $decoded['mask']);
        same(0, $decoded['corrected']);
        same([$qr['mode']], $decoded['segments']);
    });
}

$decoderReference = json_decode(file_get_contents(__DIR__ . '/fixtures/decoder.json'), true, flags: JSON_THROW_ON_ERROR);
foreach ($decoderReference['vectors'] as $vector) {
    test('Decoder external matrix: ' . $vector['name'], static function () use ($vector): void {
        $matrix = array_map(static fn(string $row): array => array_map('intval', str_split($row)), $vector['rows']);
        $needsConversion = in_array($vector['name'], ['Shift-JIS ECI', 'Kanji', 'invalid Shift-JIS'], true);
        if ($needsConversion && !function_exists('mb_convert_encoding') && !function_exists('iconv')) {
            try {
                Decoder::decodeMatrix($matrix);
                throw new LogicException('Expected a missing encoding extension error.');
            } catch (RuntimeException $error) {
                check(str_contains($error->getMessage(), 'mbstring or iconv'), 'Missing conversion extension diagnostic');
            }
            return;
        }
        if (isset($vector['error'])) {
            try {
                Decoder::decodeMatrix($matrix);
                throw new LogicException('Expected malformed or unsupported data to be rejected.');
            } catch (RuntimeException $error) {
                check(str_contains($error->getMessage(), $vector['error']), $error->getMessage());
            }
            return;
        }
        $decoded = Decoder::decodeMatrix($matrix);
        foreach (['text', 'segments', 'version', 'ecc', 'mask'] as $key) {
            same($vector[$key], $decoded[$key]);
        }
        same(0, $decoded['corrected']);
    });
}

test('Encoder decoder helpers expose validated block and function information', static function (): void {
    same(['blocks' => 1, 'eccLen' => 7, 'rawCodewords' => 26], Encoder::blockInfo(1, 'l'));
    same(['blocks' => 81, 'eccLen' => 30, 'rawCodewords' => 3706], Encoder::blockInfo(40, 'H'));
    foreach (range(1, 40) as $version) {
        $mask = Encoder::functionMask($version);
        $size = 17 + 4 * $version;
        same($size, count($mask));
        $dataModules = 0;
        foreach ($mask as $row) {
            same($size, count($row));
            foreach ($row as $module) {
                check(is_bool($module), 'Function mask entries must be booleans.');
                $dataModules += $module ? 0 : 1;
            }
        }
        same(Encoder::blockInfo($version, 'L')['rawCodewords'], intdiv($dataModules, 8));
        same(true, $mask[0][0]);
        same(false, $mask[$size - 1][$size - 1]);
    }
    foreach ([0, 41, PHP_INT_MAX] as $version) {
        throws(InvalidArgumentException::class, static fn() => Encoder::functionMask($version));
        throws(InvalidArgumentException::class, static fn() => Encoder::blockInfo($version, 'L'));
    }
    throws(InvalidArgumentException::class, static fn() => Encoder::blockInfo(1, 'invalid'));
});

test('Decoder accepts boolean and reindexed matrices and preserves input', static function (): void {
    $modules = Encoder::encode('MATRIX')['modules'];
    $boolean = [];
    foreach ($modules as $y => $row) {
        $boolean['row' . $y] = array_combine(range(10, 10 + count($row) - 1), array_map('boolval', $row));
    }
    $original = $boolean;
    same('MATRIX', Decoder::decodeMatrix($boolean)['text']);
    same($original, $boolean);
});

test('Decoder validates matrix dimensions and module types', static function (): void {
    $valid = Encoder::encode('valid')['modules'];
    $ragged = $valid;
    array_pop($ragged[0]);
    $badRow = $valid;
    $badRow[0] = 'not an array';
    foreach ([[], array_fill(0, 20, []), array_fill(0, 22, []), array_fill(0, 181, []), $ragged, $badRow] as $matrix) {
        throws(InvalidArgumentException::class, static fn() => Decoder::decodeMatrix($matrix));
    }
    foreach ([null, 2, -1, '1', 1.0, [], new stdClass()] as $value) {
        $matrix = $valid;
        $matrix[0][0] = $value;
        throws(InvalidArgumentException::class, static fn() => Decoder::decodeMatrix($matrix));
    }
    throws(RuntimeException::class, static fn() => Decoder::decodeMatrix(array_fill(0, 21, array_fill(0, 21, 0))));
});

/** Coordinates in QR data placement order, excluding all function patterns. */
function dataCoordinates(int $version): array
{
    $function = Encoder::functionMask($version);
    $size = count($function);
    $positions = [];
    $up = true;
    for ($right = $size - 1; $right > 0; $right -= 2) {
        if ($right === 6) {
            $right--;
        }
        $rows = $up ? range($size - 1, 0) : range(0, $size - 1);
        foreach ($rows as $y) {
            foreach ([$right, $right - 1] as $x) {
                if (!$function[$y][$x]) {
                    $positions[] = [$x, $y];
                }
            }
        }
        $up = !$up;
    }
    return $positions;
}

test('Decoder corrects data and parity errors up to the single-block limit', static function (): void {
    foreach (['L', 'M', 'Q', 'H'] as $ecc) {
        $qr = Encoder::encode('12345', $ecc, false, 2);
        $info = Encoder::blockInfo(1, $ecc);
        $positions = dataCoordinates(1);
        $count = intdiv($info['eccLen'], 2);
        foreach ([0, $info['rawCodewords'] - $count] as $start) {
            $modules = $qr['modules'];
            for ($i = 0; $i < $count; $i++) {
                [$x, $y] = $positions[($start + $i) * 8];
                $modules[$y][$x] ^= 1;
            }
            $result = Decoder::decodeMatrix($modules);
            same('12345', $result['text']);
            same($count, $result['corrected']);
        }
    }
});

test('Decoder corrects interleaved blocks and refuses excessive corruption', static function (): void {
    $data = str_repeat('multiple blocks ', 15);
    $qr = Encoder::encode($data, 'Q', false, 0);
    $positions = dataCoordinates($qr['version']);
    $blocks = Encoder::blockInfo($qr['version'], 'Q')['blocks'];
    $modules = $qr['modules'];
    for ($i = 0; $i < $blocks * 2; $i++) {
        [$x, $y] = $positions[$i * 8];
        $modules[$y][$x] ^= 1;
    }
    $decoded = Decoder::decodeMatrix($modules);
    same($data, $decoded['text']);
    same($blocks * 2, $decoded['corrected']);

    $modules = Encoder::encode('12345', 'L', false, 0)['modules'];
    $positions = dataCoordinates(1);
    for ($i = 0; $i < 10; $i++) {
        [$x, $y] = $positions[$i * 8];
        $modules[$y][$x] ^= 1;
    }
    throws(RuntimeException::class, static fn() => Decoder::decodeMatrix($modules));
});

test('Decoder recovers damaged format information from both copies', static function (): void {
    $qr = Encoder::encode('FORMAT', 'Q', false, 5);
    $size = $qr['size'];
    $modules = $qr['modules'];
    for ($i = 0; $i < 3; $i++) {
        $modules[$i][8] ^= 1;
        $modules[8][$size - 1 - $i] ^= 1;
    }
    $decoded = Decoder::decodeMatrix($modules);
    same('FORMAT', $decoded['text']);
    same('Q', $decoded['ecc']);
    same(5, $decoded['mask']);
    same(0, $decoded['corrected']); // This count covers Reed-Solomon codewords only.
});

test('Decoder interprets unlabelled non-UTF8 bytes as Latin-1 text', static function (): void {
    $qr = Encoder::encode("\0\xff\xfe café");
    same("\0ÿþ cafÃ©", Decoder::decodeMatrix($qr['modules'])['text']);
});

test('Decoder validates file paths and image byte limits', static function (): void {
    throws(InvalidArgumentException::class, static fn() => Decoder::decodeFile(''));
    throws(InvalidArgumentException::class, static fn() => Decoder::decodeFile("bad\0path"));
    throws(RuntimeException::class, static fn() => Decoder::decodeFile(__DIR__ . '/fixtures/missing.png'));
    throws(RuntimeException::class, static fn() => Decoder::decodeFile(__DIR__));
    throws(RuntimeException::class, static fn() => Decoder::decodeString(str_repeat('x', 20000001)));
    foreach (['', 'not an image', '<svg xmlns="http://www.w3.org/2000/svg"/>'] as $invalid) {
        throws(RuntimeException::class, static fn() => Decoder::decodeString($invalid));
    }
    $path = tempnam(sys_get_temp_dir(), 'tinyqr-decode-');
    try {
        $handle = fopen($path, 'wb');
        ftruncate($handle, 20000001);
        fclose($handle);
        throws(RuntimeException::class, static fn() => Decoder::decodeFile($path));
    } finally {
        unlink($path);
    }
});

function requireGd(): void
{
    if (!function_exists('imagecreatefromstring')) {
        skip('Image decoding requires the optional GD extension.');
    }
}

test('Decoder reads generated PNG bytes and files with colors and transparency', static function (): void {
    requireGd();
    $path = tempnam(sys_get_temp_dir(), 'tinyqr-decode-');
    try {
        foreach ([['12345', 50, false], ['HELLO WORLD', 301, false], ['বাংলা café 😀', 400, true]] as [$data, $size, $transparent]) {
            $qr = Generator::create($data, $size)->setColors('123456')->setTransparentBackground($transparent);
            same($data, Decoder::decodeString($qr->getPng())['text']);
            $qr->saveToFile($path);
            same($data, Decoder::decodeFile($path)['text']);
        }
    } finally {
        unlink($path);
    }
});

test('Decoder handles rotation, mirroring, inversion and larger raster images', static function (): void {
    requireGd();
    $data = 'https://example.com/decoder';
    $image = imagecreatefromstring(Generator::create($data, 240)->getPng());
    foreach ([90, 180, 270, 17, -28] as $angle) {
        $rotated = imagerotate($image, $angle, 0xffffff);
        same($data, Decoder::decodeImage($rotated)['text'], "Rotation {$angle}");
        unset($rotated);
    }
    imageflip($image, IMG_FLIP_HORIZONTAL);
    same($data, Decoder::decodeImage($image)['text']);
    imageflip($image, IMG_FLIP_HORIZONTAL);
    imagefilter($image, IMG_FILTER_NEGATE);
    same($data, Decoder::decodeImage($image)['text']);
    imagefilter($image, IMG_FILTER_NEGATE);
    $large = imagescale($image, 1500, 1500, IMG_NEAREST_NEIGHBOUR);
    same($data, Decoder::decodeImage($large)['text']);
    same(240, imagesx($image), 'Decoding must not resize the caller image.');
});

test('Decoder reads JPEG and rejects blank or oversized images', static function (): void {
    requireGd();
    $data = 'JPEG decoder';
    $image = imagecreatefromstring(Generator::create($data, 300)->getPng());
    imagepalettetotruecolor($image);
    ob_start();
    try {
        imagejpeg($image, null, 90);
        $jpeg = ob_get_contents();
    } finally {
        ob_end_clean();
    }
    same($data, Decoder::decodeString($jpeg)['text']);
    $blank = imagecreatetruecolor(21, 21);
    imagefill($blank, 0, 0, 0xffffff);
    throws(RuntimeException::class, static fn() => Decoder::decodeImage($blank));

    // A valid header is enough to reject excessive dimensions before decompression.
    $header = pack('NNCCCCC', 10000, 10000, 8, 2, 0, 0, 0);
    $png = "\x89PNG\r\n\x1a\n" . pack('N', strlen($header)) . 'IHDR' . $header . pack('N', crc32('IHDR' . $header));
    throws(RuntimeException::class, static fn() => Decoder::decodeString($png));
});

test('Decoder explains missing GD without affecting matrix decoding', static function (): void {
    if (function_exists('imagecreatefromstring')) {
        skip('GD is installed and available; the missing-GD scenario is intentionally skipped.');
    }
    try {
        Decoder::decodeString(Generator::create('no GD needed to encode')->getPng());
        throw new LogicException('Expected missing-GD error.');
    } catch (RuntimeException $error) {
        check(str_contains($error->getMessage(), 'GD extension'), 'Missing-GD diagnostic');
    }
});

test('Decoder downsamples large images without making a full-size copy', static function (): void {
    requireGd();
    $process = proc_open(
        [PHP_BINARY, '-d', 'memory_limit=128M', __DIR__ . '/fixtures/decode-large-image.php'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    check(is_resource($process), 'Could not start the memory-limit regression check.');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    same(0, proc_close($process), $output . $errors);
    same("Large image decoded.\n", $output);
});
