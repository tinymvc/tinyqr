# TinyQR

**Generate and decode QR codes in pure PHP.** PNG and SVG output, no HTTP requests, no third-party packages.

![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777bb4)
![License: MIT](https://img.shields.io/badge/license-MIT-green)
[![Tests](https://img.shields.io/badge/tests-PHP%20runner-brightgreen.svg)](tests/)

```php
use Spark\Qr\Generator;

Generator::create('https://example.com')->saveToFile(__DIR__ . '/qr.png');
```

[Install](#installation) · [Quick start](#quick-start) · [Output](#output) · [Configuration](#configuration) · [Payload helpers](#payload-helpers) · [Decoding](#decoding) · [Errors](#limits-and-errors) · [Tests](#tests)

## Features

- **Complete QR Code Model 2 support:** versions 1–40 with automatic numeric, alphanumeric, or byte mode selection.
- **Error correction:** levels L, M, Q, and H, with optional automatic boosting.
- **Flexible rendering:** PNG or SVG, custom colors, transparency, automatic or fixed mask, and a configurable quiet zone.
- **Ready-made payloads:** WiFi, TOTP authenticator setup, email, phone, SMS, location, and contact (vCard) helpers.
- **Built-in decoder:** reads module matrices and raster images, with Reed-Solomon error correction.
- **Self-contained:** generation and matrix decoding need no GD or ImageMagick. Everything runs locally.

## Requirements

| Requirement | Needed for |
| --- | --- |
| PHP 8.2 or later | Everything |
| `filter`, `zlib` | Required. PNG compression uses zlib. |
| `gd` | Optional. Enables image decoding. |
| `mbstring` or `iconv` | Optional. Decoding Kanji or Shift-JIS. |

## Installation

```sh
composer require tinymvc/tinyqr
```

The Composer package is `tinymvc/tinyqr`; its PHP namespace is `Spark\Qr`.

## Quick start

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Spark\Qr\Generator;

$qr = Generator::create('https://example.com');
$qr->saveToFile(__DIR__ . '/qr.png');
```

Defaults: PNG, 300 × 300 pixels, black modules on white, and a four-module quiet zone. The minimum error correction level is M. It is raised automatically when a stronger level fits without increasing the QR version.

## Output

```php
$qr = Generator::create('Hello from TinyQR');

$png = $qr->getPng();
$svg = $qr->getSvg();

$qr->setFormat('svg');
$raw  = $qr->getRawData();
$mime = $qr->getMimeType();      // image/svg+xml
$uri  = $qr->getBase64DataUri();

echo '<img src="' . $uri . '" alt="QR code">';
$qr->saveToFile(__DIR__ . '/qr.svg');
```

`getPng()` and `getSvg()` return the requested format without changing the configured format. `getRawData()`, `getMimeType()`, `getBase64DataUri()`, `saveToFile()`, and `renderToBrowser()` use the configured format.

`saveToFile()` returns `true` after a complete write and throws on failure. The parent directory must already exist and be writable. Existing files are overwritten. The filename extension does not select the output format.

### Serving an image from an endpoint

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Spark\Qr\Generator;

Generator::create('https://example.com')->renderToBrowser();
exit;
```

Call `renderToBrowser()` before any output. It sends the image MIME type, content length, ETag, and `X-Content-Type-Options: nosniff`. Responses use private caching with revalidation. GET and HEAD support `If-None-Match`, including weak validators, lists, and `*`; a match returns 304 with no body. The method does not end script execution.

## Configuration

The constructor and the `create()` factory accept the same arguments:

```php
$qr = new Generator(
    data: 'https://example.com',
    size: 400,
    ecc: 'Q',
    margin: 4,
);

$qr->setColors('#123456', '#ffffff')
    ->setTransparentBackground()
    ->setBoostErrorCorrection(false)
    ->setMask(3)
    ->setFormat('svg');
```

All setters return the same instance, so they can be chained.

| Method | Accepted values | Default |
| --- | --- | --- |
| `setData(string $data)` | Any non-empty string; whitespace and null bytes are preserved | — |
| `setSize(int $size)` | 50–1000 pixels | `300` |
| `setErrorCorrection(string $ecc)` | `L`, `M`, `Q`, or `H` (case-insensitive) | `M` (minimum) |
| `setMargin(int $margin)` | 0–50 modules on each side | `4` |
| `setColors(string $foregroundHex, string $backgroundHex = 'ffffff')` | Six-digit hex colors, optional leading `#`; contrast of at least 3:1 | black on white |
| `setFormat(string $format)` | `png` or `svg` (case-insensitive) | `png` |
| `setTransparentBackground(bool $transparent = true)` | Enable or disable background transparency | off |
| `setBoostErrorCorrection(bool $boost)` | Enable or disable automatic ECC boosting | on |
| `setMask(int $mask)` | `-1` for automatic selection, or a fixed mask from 0–7 | `-1` |

### Scanning tips

- Keep a quiet zone of at least four modules.
- Use dark modules on a light background. A contrast check alone cannot guarantee that a scanner will read a particular color combination.
- Transparent images need a suitable background when displayed.

### Sizing

PNG uses integer module scaling and centers the code on a canvas of exactly the requested size. If the modules and margin cannot fit at one pixel per module, PNG rendering throws `InvalidArgumentException`; increase the size. SVG keeps the requested dimensions and scales through its `viewBox`.

## Payload helpers

Helpers return strings. Pass the result to `Generator::create()` or `setData()`. Plain text and URLs can be passed directly.

### WiFi

```php
$payload = Generator::wifiPayload(
    ssid: 'Guest Network',
    password: 'secret-password',
    auth: 'WPA',
    hidden: false,
);

Generator::create($payload)->saveToFile(__DIR__ . '/wifi.png');

// Open network; the password is omitted from the payload.
$open = Generator::wifiPayload('Guest Network', auth: 'nopass');
```

`auth` accepts `WPA`, `WEP`, or `nopass` (case-insensitive). The SSID must be non-empty. Backslashes and WiFi field delimiters are escaped.

> [!WARNING]
> A WiFi QR code contains the password in readable form.

### TOTP authenticator setup

```php
// Use the Base32 shared secret supplied by your authentication backend.
$payload = Generator::totpPayload(
    secret: $secret,
    account: 'alice@example.com',
    issuer: 'My App',
);

Generator::create($payload)->saveToFile(__DIR__ . '/totp.png');
```

This builds an `otpauth://totp/…` provisioning URI using the [authenticator key URI format](https://github.com/google/google-authenticator/wiki/Key-Uri-Format). The account and issuer are URL-encoded, and the same issuer appears in both the label prefix and the query parameter.

The first three arguments are required. Optional settings must match your authentication backend:

| Parameter | Default | Accepted values |
| --- | --- | --- |
| `algorithm` | `SHA1` | `SHA1`, `SHA256`, or `SHA512`, case-insensitive |
| `digits` | `6` | `6` or `8` |
| `period` | `30` | A positive integer, in seconds |

For example, use `Generator::totpPayload($secret, 'alice@example.com', 'My App', algorithm: 'SHA256', digits: 8, period: 60)` for custom settings. Authenticator support for non-default settings varies.

Secrets must be non-empty Base32 (`A–Z` and `2–7`), with optional valid trailing `=` padding. Lowercase is accepted; output is uppercase without padding. Invalid characters, encoded lengths, padding lengths, and non-zero unused bits are rejected. Account and issuer must be non-blank UTF-8 without colons or control characters. Invalid inputs throw `InvalidArgumentException`. Validation checks the encoding; use a securely generated secret from your authentication backend.

> [!WARNING]
> The URI and QR code contain the shared authentication secret. Keep them private.

### Email, phone, and SMS

```php
$email = Generator::emailPayload(
    email: 'hello@example.com',
    subject: 'Hello',
    body: "Thanks for getting in touch.\nSee you soon!",
);

$phone = Generator::phonePayload('+1 (555) 123-4567');
$sms   = Generator::smsPayload('+1 (555) 123-4567', 'Hello there!');
```

- **Email** produces a `mailto:` URI with encoded subject and body parameters.
- **Phone** numbers may contain digits, an optional leading `+`, spaces, parentheses, dots, and hyphens. Formatting characters are removed. Extensions are not supported.
- **SMS** produces an `sms:` URI with an optional `body` parameter.

### Location

```php
$location = Generator::geoPayload(23.8103, 90.4125);
// geo:23.8103,90.4125
```

Latitude must be between −90 and 90, and longitude between −180 and 180. Coordinates must be finite and are rounded to six decimal places using a decimal point.

### Contact

```php
$contact = Generator::vCardPayload(
    firstName: 'Ada',
    lastName: 'Lovelace',
    phone: '+15551234567',
    email: 'ada@example.com',
    organization: 'Example Company',
    url: 'https://example.com',
);

Generator::create($contact, size: 500)->saveToFile(__DIR__ . '/contact.png');
```

This produces a vCard 3.0 with structured and formatted names, escaped text, and CRLF line endings. All arguments after `firstName` are optional. At least one name must be non-blank, and non-empty email addresses, phone numbers, and URLs are validated.

How a phone opens any payload depends on its QR reader and installed apps.

## Matrix access

```php
use Spark\Qr\Encoder;

$matrix = Generator::create('HELLO WORLD')->getMatrix();

// Encode without a renderer; disable boosting and force mask 0.
$matrix = Encoder::encode('HELLO WORLD', ecc: 'M', boostEcc: false, mask: 0);
```

Both return the same structure:

```php
[
    'version' => 1,       // QR version, 1–40
    'ecc'     => 'M',     // Actual error correction level after boosting
    'mask'    => 0,       // Selected mask, 0–7
    'size'    => 21,      // Width and height in modules, excluding the quiet zone
    'mode'    => 'alnum', // numeric, alnum, or byte
    'modules' => [...],   // Rows indexed as [y][x]; 1 is dark and 0 is light
]
```

The generator caches its matrix. Changes to data, ECC, boosting, or mask invalidate the cache; visual settings do not.

For custom QR tooling, `Encoder::functionMask(int $version)` returns the boolean function-pattern map, and `Encoder::blockInfo(int $version, string $ecc)` returns `blocks`, `eccLen`, and `rawCodewords`. Both validate versions 1–40.

## Decoding

```php
use Spark\Qr\Decoder;

$result = Decoder::decodeFile(__DIR__ . '/qr.png');
echo $result['text'];

// Decode raw image bytes, without saving a file.
$result = Decoder::decodeString(Generator::create('Hello')->getPng());

// Decode modules directly; GD is not required.
$matrix = Generator::create('Hello')->getMatrix();
$result = Decoder::decodeMatrix($matrix['modules']);
```

Each method returns:

```php
[
    'text'      => 'Hello',
    'version'   => 1,
    'ecc'       => 'H',
    'mask'      => 5,       // Actual mask read from the symbol
    'corrected' => 0,       // Number of Reed-Solomon codewords repaired
    'segments'  => ['byte'],
]
```

| Method | Input |
| --- | --- |
| `Decoder::decodeFile(string $path)` | Readable local image file |
| `Decoder::decodeString(string $image)` | Raw PNG/JPEG/GIF/WebP/BMP bytes, subject to the formats enabled in GD |
| `Decoder::decodeImage(\GdImage $im)` | An existing GD image; the caller's image is not modified |
| `Decoder::decodeMatrix(array $modules)` | Upright square module rows, without the quiet zone; values must be `0`, `1`, `false`, or `true` |

### What the decoder handles

- **Matrix decoding:** versions 1–40 and all four correction levels.
- **Image decoding:** locates a single code and handles rotation, mirroring, inversion, transparency, and scaling. It uses affine sampling, so strong perspective distortion, curved surfaces, and heavily damaged images may not decode.
- **SVG:** rasterize it first, or decode through the original module matrix.
- **Segments:** numeric, alphanumeric, byte, Kanji, and mixed. Kanji and Shift-JIS need `mbstring` or `iconv`; other supported encodings need neither.
- **ECI:** ISO-8859-1 (`3`), Shift-JIS (`20`), and UTF-8 (`26`).

### Text interpretation

Returned `text` is interpreted text. Without ECI, valid UTF-8 is preserved and other bytes are interpreted as Latin-1. It is not an exact binary-payload extraction API.

Malformed data, unknown ECI assignments, structured append, and FNC1 are rejected rather than silently returned as partial or misinterpreted text. The `corrected` count covers repaired data/parity codewords, not repaired format bits.

### Image limits

Image inputs are limited to 20 MB of encoded bytes and 40 million pixels. Large images are downscaled, and image searches use a best-effort ten-second budget.

## Limits and errors

TinyQR selects one mode for the entire input and chooses the smallest version that fits. At level L, version 40 holds up to 7,089 numeric characters, 4,296 alphanumeric characters, or 2,953 bytes without ECI. Higher correction levels reduce capacity.

Non-ASCII strings that are valid UTF-8 receive an ECI 26 declaration so readers can identify the encoding. That declaration consumes capacity. Other byte strings are encoded unchanged without ECI; the encoder does not transcode input.

The encoder does not generate Kanji mode, mixed-mode segments, Micro QR, or structured append.

| Exception | Thrown when |
| --- | --- |
| `InvalidArgumentException` | Input is empty; settings or helper arguments are invalid; a file path is invalid; a PNG canvas is too small; a decoder matrix has an invalid shape or module values |
| `LengthException` | Input exceeds QR capacity for the requested correction level |
| `RuntimeException` | A file cannot be written; HTTP headers have already been sent; an image is unreadable or a symbol is uncorrectable |

Encoding is lazy, so capacity errors occur when you request a matrix or output.

## Upgrade notes

Behavior that changed since the initial implementation:

- Input is preserved exactly instead of being trimmed.
- The default margin is four modules instead of two.
- PNG refuses undersized canvases instead of silently enlarging them.
- File write failures now throw consistently, and browser responses use private caching.
- UTF-8 declarations and corrected mask scoring can change the generated matrix for the same input.

## Tests

```sh
php tests/run.php

# Or, with Composer:
composer test

# Run only decoder tests:
php tests/run.php Decoder
```

The custom runner needs PHP alone: no PHPUnit, no package installation, and no network access to external services.

**Encoder tests** check 188 independent vectors, all 40 versions and four ECC levels, all masks, capacity limits, UTF-8 and binary input, PNG chunks and pixels, SVG paths, payload escaping, file output, and HTTP behavior.

**Decoder tests** cover matrix round trips, independent mixed-mode/Kanji/ECI matrices, malformed segments, error correction, image transformations, and image/file limits.

Notes:

- Image-decoding tests skip when GD is unavailable. Check missing-GD behavior with `php -d disable_functions=imagecreatefromstring tests/run.php Decoder`. Missing character conversion extensions are also handled and tested.
- The HTTP test starts a short-lived PHP server on a local ephemeral port, so it requires CLI process creation (`proc_open`) and loopback sockets. Temporary files and the server are cleaned up.

## References

Expected matrix hashes and decoder matrices were generated separately with [Project Nayuki's QR encoder](https://www.nayuki.io/page/qr-code-generator-library). The exact source revision, checksum, generation recipes, and inputs are recorded in the [encoder fixtures](tests/fixtures/reference.json) and [decoder fixtures](tests/fixtures/decoder.json). The reference implementation is not a runtime or test dependency.

Payload formats follow [ZXing's barcode content conventions](https://github.com/zxing/zxing/wiki/Barcode-Contents), [RFC 5724](https://www.rfc-editor.org/rfc/rfc5724) (SMS), and [RFC 2426](https://www.rfc-editor.org/rfc/rfc2426) (vCard 3.0).

## License

[MIT](LICENSE)
