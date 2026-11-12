<?php

declare(strict_types=1);

require __DIR__ . '/../../src/Encoder.php';
require __DIR__ . '/../../src/Generator.php';
require __DIR__ . '/../../src/Decoder.php';

$data = 'Decode a 12-megapixel image within 128 MiB.';
$qr = imagecreatefromstring(Spark\Qr\Generator::create($data, 600)->getPng());
$image = imagecreatetruecolor(4000, 3000);
imagefill($image, 0, 0, 0xffffff);
imagecopy($image, $qr, 1700, 1200, 0, 0, 600, 600);
unset($qr);

$result = Spark\Qr\Decoder::decodeImage($image);
if ($result['text'] !== $data || imagesx($image) !== 4000 || imagesy($image) !== 3000) {
    throw new RuntimeException('Large-image decoding failed or modified the caller image.');
}
echo "Large image decoded.\n";
