<?php

declare(strict_types=1);

require __DIR__ . '/../../src/Encoder.php';
require __DIR__ . '/../../src/Generator.php';

if (isset($_GET['headers_sent'])) {
    echo 'sent';
    flush();
    try {
        Spark\Qr\Generator::create('HTTP test')->renderToBrowser();
    } catch (RuntimeException) {
        echo ':caught';
    }
    return;
}

Spark\Qr\Generator::create('HTTP test')
    ->setFormat($_GET['format'] ?? 'png')
    ->renderToBrowser();
