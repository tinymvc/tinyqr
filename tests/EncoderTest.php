<?php

declare(strict_types=1);

use Spark\Qr\Encoder;

$reference = json_decode(file_get_contents(__DIR__ . '/fixtures/reference.json'), true, flags: JSON_THROW_ON_ERROR);
foreach ($reference['vectors'] as $vector) {
    test('Reference: ' . $vector['name'], static function () use ($vector): void {
        $data = str_repeat(base64_decode($vector['data'], true), $vector['repeat']);
        $qr = Encoder::encode($data, $vector['requestedEcc'], $vector['boost'], $vector['requestedMask']);
        same($vector['version'], $qr['version']);
        same($vector['ecc'], $qr['ecc']);
        same($vector['mask'], $qr['mask']);
        same($qr['version'] * 4 + 17, $qr['size']);
        same($qr['size'], count($qr['modules']));
        $bits = '';
        foreach ($qr['modules'] as $row) {
            same($qr['size'], count($row));
            foreach ($row as $module) {
                check($module === 0 || $module === 1, 'Modules must be integer bits.');
            }
            $bits .= implode('', $row);
        }
        same($vector['hash'], hash('sha256', $bits), 'Reference matrix mismatch');
    });
}

test('Encoder selects modes and normalizes ECC', static function (): void {
    foreach (['00123' => 'numeric', 'HELLO + WORLD' => 'alnum', "HELLO\n" => 'byte', 'é' => 'byte'] as $data => $mode) {
        same($mode, Encoder::encode($data)['mode']);
    }
    same(Encoder::encode('hello', 'M'), Encoder::encode('hello', 'm'));
});

test('Encoder validates inputs', static function (): void {
    foreach ([
        static fn() => Encoder::encode(''),
        static fn() => Encoder::encode('hello', 'X'),
        static fn() => Encoder::encode('hello', mask: -2),
        static fn() => Encoder::encode('hello', mask: 8),
    ] as $invalid) {
        throws(InvalidArgumentException::class, $invalid);
    }
});

test('Encoder rejects data beyond mode and ECC capacities', static function (): void {
    foreach ([
        [str_repeat('1', 7090), 'L'],
        [str_repeat('A', 4297), 'L'],
        [str_repeat('a', 2954), 'L'],
        [str_repeat('a', 1274), 'H'],
        ['é' . str_repeat('a', 2951), 'L'],
        [str_repeat('x', 1000000), 'L'],
    ] as [$data, $ecc]) {
        throws(LengthException::class, static fn() => Encoder::encode($data, $ecc));
    }
});
