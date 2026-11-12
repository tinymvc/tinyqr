<?php

declare(strict_types=1);

use Spark\Qr\Encoder;
use Spark\Qr\Generator;

/** Parses chunks independently, checking every CRC and the final boundary. */
function pngChunks(string $png): array
{
    same("\x89PNG\r\n\x1a\n", substr($png, 0, 8));
    $chunks = [];
    $offset = 8;
    while ($offset < strlen($png)) {
        $length = unpack('N', substr($png, $offset, 4))[1];
        $type = substr($png, $offset + 4, 4);
        $data = substr($png, $offset + 8, $length);
        same(pack('N', crc32($type . $data)), substr($png, $offset + 8 + $length, 4), "CRC for {$type}");
        $chunks[$type] = ($chunks[$type] ?? '') . $data;
        $offset += $length + 12;
    }
    same(strlen($png), $offset);
    same('IEND', $type);
    same('', $chunks['IEND']);
    return $chunks;
}

test('Generator preserves whitespace, binary and zero input', static function (): void {
    foreach ([" \t hello \r\n\0", ' ', "\0", '0'] as $data) {
        same(Encoder::encode($data), Generator::create($data)->getMatrix());
    }
    throws(InvalidArgumentException::class, static fn() => Generator::create(''));
});

test('Generator refreshes the matrix after encoding changes', static function (): void {
    $qr = Generator::create('hello');
    $qr->getMatrix();
    same($qr, $qr->setData('changed'));
    same(Encoder::encode('changed'), $qr->getMatrix());
    $qr->setErrorCorrection('l')->setBoostErrorCorrection(false)->setMask(3);
    same(Encoder::encode('changed', 'L', false, 3), $qr->getMatrix());
    $qr->setMask(-1)->setBoostErrorCorrection(true)->setErrorCorrection('q');
    same(Encoder::encode('changed', 'Q'), $qr->getMatrix());
    $matrix = $qr->getMatrix();
    $qr->setSize(400)->setMargin(6)->setFormat('svg')->setColors('123456')->setTransparentBackground();
    same($matrix, $qr->getMatrix());
    $matrix['modules'][0][0] ^= 1;
    check($matrix !== $qr->getMatrix(), 'Returned matrix must not mutate the cached matrix.');
});

test('Generator validates options without corrupting state', static function (): void {
    $qr = Generator::create('test');
    $original = $qr->getPng();
    foreach ([
        static fn() => $qr->setData(''),
        static fn() => $qr->setSize(49),
        static fn() => $qr->setSize(1001),
        static fn() => $qr->setMargin(-1),
        static fn() => $qr->setMargin(51),
        static fn() => $qr->setErrorCorrection('invalid'),
        static fn() => $qr->setFormat('jpg'),
        static fn() => $qr->setMask(-2),
        static fn() => $qr->setMask(8),
        static fn() => $qr->setColors('fff'),
        static fn() => $qr->setColors('##000000'),
        static fn() => $qr->setColors("000000\n"),
        static fn() => $qr->setColors('000000', '<script>'),
        static fn() => $qr->setColors('ffffff', 'ffffff'),
    ] as $invalid) {
        throws(InvalidArgumentException::class, $invalid);
        same($original, $qr->getPng());
    }
});

test('PNG pixels match the encoded matrix including padding and transparency', static function (): void {
    foreach ([[50, 0, false], [300, 4, false], [301, 4, true], [1000, 50, true]] as [$size, $margin, $transparent]) {
        $qr = Generator::create('PNG audit', $size, margin: $margin)
            ->setColors('#123456', '#FFFFFF')->setTransparentBackground($transparent);
        $chunks = pngChunks($qr->getPng());
        same(pack('NNCCCCC', $size, $size, 1, 3, 0, 0, 0), $chunks['IHDR']);
        same(hex2bin('ffffff123456'), $chunks['PLTE']);
        same($transparent, isset($chunks['tRNS']));
        if ($transparent) {
            same("\0", $chunks['tRNS']);
        }
        $pixels = gzuncompress($chunks['IDAT']);
        $stride = intdiv($size + 7, 8) + 1;
        same($stride * $size, strlen($pixels));
        $matrix = $qr->getMatrix();
        $scale = intdiv($size, $matrix['size'] + 2 * $margin);
        $offset = intdiv($size - ($matrix['size'] + 2 * $margin) * $scale, 2) + $margin * $scale;
        for ($y = 0; $y < $size; $y++) {
            same(0, ord($pixels[$y * $stride]), 'PNG row filter');
            for ($x = 0; $x < $size; $x++) {
                $actual = (ord($pixels[$y * $stride + 1 + intdiv($x, 8)]) >> (7 - $x % 8)) & 1;
                $inside = $x >= $offset && $y >= $offset
                    && $x < $offset + $matrix['size'] * $scale && $y < $offset + $matrix['size'] * $scale;
                $expected = $inside ? $matrix['modules'][intdiv($y - $offset, $scale)][intdiv($x - $offset, $scale)] : 0;
                same($expected, $actual, "PNG pixel {$x},{$y}");
            }
        }
    }
});

test('PNG refuses a canvas too small for its modules and quiet zone', static function (): void {
    $qr = Generator::create(str_repeat('a', 150), 50);
    throws(InvalidArgumentException::class, static fn() => $qr->getPng());
    check(str_contains($qr->getSvg(), 'width="50"'), 'SVG remains scalable.');
});

test('SVG paths reproduce all modules and the default four-module margin', static function (): void {
    $qr = Generator::create('<script>alert("QR");</script>', 301)->setColors('#123456');
    $svg = $qr->getSvg();
    $matrix = $qr->getMatrix();
    $total = $matrix['size'] + 8;
    check(str_contains($svg, 'width="301" height="301"'), 'SVG size');
    check(str_contains($svg, "viewBox=\"0 0 {$total} {$total}\""), 'Default quiet zone');
    check(str_contains($svg, 'fill="#123456"'), 'SVG foreground');
    check(!str_contains($svg, '<script>'), 'Payload is not embedded as markup.');
    preg_match('/ d="([^"]*)"/', $svg, $path);
    preg_match_all('/M(\d+) (\d+)h(\d+)v1h-(\d+)z/', $path[1], $runs, PREG_SET_ORDER);
    same($path[1], implode('', array_column($runs, 0)), 'Entire path must be parsed.');
    $actual = array_fill(0, $matrix['size'], array_fill(0, $matrix['size'], 0));
    foreach ($runs as [, $x, $y, $width, $back]) {
        same($width, $back);
        for ($i = 0; $i < (int) $width; $i++) {
            $actual[(int) $y - 4][(int) $x - 4 + $i] = 1;
        }
    }
    same($matrix['modules'], $actual);
    check(str_contains($svg, '<rect'), 'Opaque background');
    check(!str_contains($qr->setTransparentBackground()->getSvg(), '<rect'), 'Transparent background');
    check(str_contains($qr->setTransparentBackground(false)->getSvg(), '<rect'), 'Restored background');
});

test('Output helpers respect the selected format', static function (): void {
    $qr = Generator::create('hello');
    same('image/png', $qr->getMimeType());
    same($qr->getPng(), $qr->getRawData());
    $qr->getSvg();
    same('image/png', $qr->getMimeType());
    foreach (['PNG' => 'image/png', 'SVG' => 'image/svg+xml'] as $format => $mime) {
        same($qr, $qr->setFormat($format));
        same($mime, $qr->getMimeType());
        same('data:' . $mime . ';base64,' . base64_encode($qr->getRawData()), $qr->getBase64DataUri());
    }
    $qr->getPng();
    same('image/svg+xml', $qr->getMimeType());
});

test('Saving writes exact bytes and reports invalid paths or write failures', static function (): void {
    $path = tempnam(sys_get_temp_dir(), 'tinyqr-');
    try {
        $qr = Generator::create('file output');
        foreach (['png', 'svg'] as $format) {
            $qr->setFormat($format);
            same(true, $qr->saveToFile($path));
            same($qr->getRawData(), file_get_contents($path));
        }
        throws(InvalidArgumentException::class, static fn() => $qr->saveToFile(''));
        throws(InvalidArgumentException::class, static fn() => $qr->saveToFile("bad\0path"));
        throws(RuntimeException::class, static fn() => $qr->saveToFile($path . '/missing/code.png'));
        throws(RuntimeException::class, static fn() => $qr->saveToFile(sys_get_temp_dir()));
    } finally {
        unlink($path);
    }
});
