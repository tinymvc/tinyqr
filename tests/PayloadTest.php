<?php

declare(strict_types=1);

use Spark\Qr\Generator;
use Spark\Qr\Decoder;

test('WiFi escapes delimiters and handles open and hidden networks', static function (): void {
    same('WIFI:T:WPA;S:Guest;P:secret;;', Generator::wifiPayload('Guest', 'secret', 'wpa'));
    same('WIFI:T:nopass;S:Guest;H:true;;', Generator::wifiPayload('Guest', 'ignored', 'nopass', true));
    same('WIFI:T:WEP;S:a\;b\,c\:d\"e\\\\f;P:p\;q;;', Generator::wifiPayload('a;b,c:d"e\\f', 'p;q', 'WEP'));
    throws(InvalidArgumentException::class, static fn() => Generator::wifiPayload(''));
    throws(InvalidArgumentException::class, static fn() => Generator::wifiPayload('ssid', auth: 'invalid'));
});

test('TOTP payload uses authenticator defaults and round-trips through a QR matrix', static function (): void {
    $payload = Generator::totpPayload('JBSWY3DPEHPK3PXP', 'alice@example.com', 'Example');
    same(
        'otpauth://totp/Example:alice%40example.com?secret=JBSWY3DPEHPK3PXP&issuer=Example'
        . '&algorithm=SHA1&digits=6&period=30',
        $payload
    );
    same($payload, Decoder::decodeMatrix(Generator::create($payload)->getMatrix()['modules'])['text']);
});

test('TOTP payload encodes labels and supports custom authenticator settings', static function (): void {
    $account = 'alice+qr@example.com /?#&=😀';
    $issuer = 'ACME & Sons /?#=বাংলা';
    foreach (['sha1' => 'SHA1', 'sha256' => 'SHA256', 'sha512' => 'SHA512'] as $input => $algorithm) {
        $uri = Generator::totpPayload('JBSWY3DPEHPK3PXP', $account, $issuer, $input, 8, 60);
        $parts = parse_url($uri);
        same('otpauth', $parts['scheme']);
        same('totp', $parts['host']);
        same('/' . $issuer . ':' . $account, rawurldecode($parts['path']));
        check(!isset($parts['fragment']), 'Labels must not inject a URI fragment.');
        check(!str_contains($uri, ' '), 'Spaces must be percent-encoded.');
        parse_str($parts['query'], $query);
        same([
            'secret' => 'JBSWY3DPEHPK3PXP',
            'issuer' => $issuer,
            'algorithm' => $algorithm,
            'digits' => '8',
            'period' => '60',
        ], $query);
    }
});

test('TOTP accepts canonical Base32 vectors and normalizes case and padding', static function (): void {
    // RFC 4648 vectors cover every valid partial Base32 group.
    foreach (['MY======', 'MZXQ====', 'MZXW6===', 'MZXW6YQ=', 'MZXW6YTB', 'MZXW6YTBOI======'] as $secret) {
        $expected = rtrim($secret, '=');
        foreach ([$secret, strtolower($secret), $expected] as $input) {
            $uri = Generator::totpPayload($input, 'account', 'Issuer');
            parse_str(parse_url($uri, PHP_URL_QUERY), $query);
            same($expected, $query['secret']);
        }
    }
});

test('TOTP rejects malformed Base32 secrets', static function (): void {
    foreach ([
        '',
        ' ',
        'ABC0',
        'ABC1',
        'ABC8',
        'AB_C',
        '====',
        'MY=AAAAA',
        'A',
        'AAA',
        'AAAAAA',
        'MY=',
        'MY=====',
        'MY=======',
        'MZXW6YTB=',
        'MZ',
        'MZXR',
        'MZXW7',
        'MZXW6YR',
        "MY======\n",
        "MY\0",
        "\xff",
    ] as $secret) {
        throws(InvalidArgumentException::class, static fn() => Generator::totpPayload($secret, 'account', 'Issuer'));
    }
});

test('TOTP rejects blank, ambiguous or invalid labels', static function (): void {
    foreach (['', '  ', "\u{00a0}", 'has:colon', "line\nbreak", "nul\0byte", "\x7f", "\u{0085}", "\xff"] as $label) {
        throws(InvalidArgumentException::class, static fn() => Generator::totpPayload('JBSWY3DPEHPK3PXP', $label, 'Issuer'));
        throws(InvalidArgumentException::class, static fn() => Generator::totpPayload('JBSWY3DPEHPK3PXP', 'account', $label));
    }
});

test('TOTP validates algorithm, digit count and period', static function (): void {
    foreach (['', 'MD5', 'SHA-1', "SHA1\n"] as $algorithm) {
        throws(InvalidArgumentException::class, static fn() =>
            Generator::totpPayload('JBSWY3DPEHPK3PXP', 'account', 'Issuer', algorithm: $algorithm));
    }
    foreach ([0, 5, 7, 9] as $digits) {
        throws(InvalidArgumentException::class, static fn() =>
            Generator::totpPayload('JBSWY3DPEHPK3PXP', 'account', 'Issuer', digits: $digits));
    }
    foreach ([0, -1] as $period) {
        throws(InvalidArgumentException::class, static fn() =>
            Generator::totpPayload('JBSWY3DPEHPK3PXP', 'account', 'Issuer', period: $period));
    }
    check(
        str_ends_with(Generator::totpPayload('JBSWY3DPEHPK3PXP', 'account', 'Issuer', period: 1), '&period=1'),
        'A positive period is allowed.'
    );
});

test('Email payload encodes recipients and query parameters', static function (): void {
    same('mailto:hello@example.com', Generator::emailPayload('hello@example.com'));
    same(
        'mailto:a%2Bb@example.com?subject=Hi%20%26%20bye&body=Line%201%0ALine%202',
        Generator::emailPayload('a+b@example.com', 'Hi & bye', "Line 1\nLine 2")
    );
    same('mailto:hello@example.com?body=0', Generator::emailPayload('hello@example.com', body: '0'));
    throws(InvalidArgumentException::class, static fn() => Generator::emailPayload("a@example.com\r\nBcc:x@example.com"));
    throws(InvalidArgumentException::class, static fn() => Generator::emailPayload('invalid'));
});

test('Phone and SMS helpers normalize numbers and encode messages', static function (): void {
    same('tel:+15551234567', Generator::phonePayload('+1 (555) 123-4567'));
    same('sms:12345', Generator::smsPayload('12345'));
    same(
        'sms:+15551234567?body=Hi%20%26%20bye%0A%F0%9F%98%80',
        Generator::smsPayload('+1 (555) 123-4567', "Hi & bye\n😀")
    );
    foreach (['', '+', 'abc', "123\n", '123?body=oops', '123;ext=45'] as $invalid) {
        throws(InvalidArgumentException::class, static fn() => Generator::phonePayload($invalid));
        throws(InvalidArgumentException::class, static fn() => Generator::smsPayload($invalid));
    }
});

test('Geographic payload validates ranges and finite coordinates', static function (): void {
    same('geo:23.8103,90.4125', Generator::geoPayload(23.8103, 90.4125));
    same('geo:0,0', Generator::geoPayload(0, 0));
    same('geo:-90,180', Generator::geoPayload(-90, 180));
    foreach ([[91, 0], [0, -181], [NAN, 0], [0, INF], [-INF, 0]] as [$latitude, $longitude]) {
        throws(InvalidArgumentException::class, static fn() => Generator::geoPayload($latitude, $longitude));
    }
});

test('vCard includes required fields and escapes contact text', static function (): void {
    same(
        "BEGIN:VCARD\r\nVERSION:3.0\r\nN:Lovelace;Ada;;;\r\nFN:Ada Lovelace\r\n"
        . "TEL:+15551234567\r\nEMAIL:ada@example.com\r\nORG:ACME\\; Research\\, Inc.\\\\Lab\\nTeam\r\n"
        . "URL:https://example.com/?a=1&b=2\r\nEND:VCARD\r\n",
        Generator::vCardPayload(
            'Ada',
            'Lovelace',
            '+1 (555) 123-4567',
            'ada@example.com',
            "ACME; Research, Inc.\\Lab\r\nTeam",
            'https://example.com/?a=1&b=2'
        )
    );
    $card = Generator::vCardPayload("Ada\r\nTEL:fake", "Last;Name");
    check(str_contains($card, 'N:Last\;Name;Ada\nTEL:fake;;;'), 'Name delimiters must be escaped.');
    same(1, substr_count($card, "\r\nFN:"));
    check(!str_contains($card, "\r\nTEL:"), 'Text cannot inject vCard fields.');
    foreach ([
        static fn() => Generator::vCardPayload(' '),
        static fn() => Generator::vCardPayload('Ada', email: 'invalid'),
        static fn() => Generator::vCardPayload('Ada', phone: 'invalid'),
        static fn() => Generator::vCardPayload('Ada', url: "https://example.com/\r\nTEL:fake"),
    ] as $invalid) {
        throws(InvalidArgumentException::class, $invalid);
    }
});
