<?php

declare(strict_types=1);

test('HTTP returns PNG/SVG, private caching, conditional responses and HEAD', static function (): void {
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    check($listener !== false, 'Cannot reserve local test port: ' . $errorMessage);
    $address = stream_socket_get_name($listener, false);
    fclose($listener);
    $log = tmpfile();
    $server = proc_open(
        [PHP_BINARY, '-n', '-d', 'output_buffering=0', '-S', $address, __DIR__ . '/fixtures/http-router.php'],
        [0 => ['pipe', 'r'], 1 => $log, 2 => $log],
        $pipes
    );
    check(is_resource($server), 'Cannot start PHP HTTP test server.');
    fclose($pipes[0]);
    try {
        $ready = false;
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $connection = @stream_socket_client('tcp://' . $address, $errorCode, $errorMessage, 0.05);
            if ($connection !== false) {
                fclose($connection);
                $ready = true;
                break;
            }
            usleep(10000);
        }
        check($ready, 'HTTP test server did not start.');
        $request = static function (string $method = 'GET', string $path = '/', string $etag = '') use ($address): array {
            $socket = stream_socket_client('tcp://' . $address, $errorCode, $errorMessage, 2);
            stream_set_timeout($socket, 2);
            $conditional = $etag === '' ? '' : "If-None-Match: {$etag}\r\n";
            fwrite($socket, "{$method} {$path} HTTP/1.1\r\nHost: {$address}\r\n{$conditional}Connection: close\r\n\r\n");
            $raw = stream_get_contents($socket);
            fclose($socket);
            [$head, $body] = explode("\r\n\r\n", $raw, 2);
            $lines = explode("\r\n", $head);
            $status = (int) explode(' ', array_shift($lines))[1];
            $headers = [];
            foreach ($lines as $line) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower($name)] = trim($value);
            }
            return [$status, $headers, $body];
        };
        foreach (['png' => 'image/png', 'svg' => 'image/svg+xml'] as $format => $mime) {
            $path = '/?format=' . $format;
            [$status, $headers, $body] = $request('GET', $path);
            same(200, $status);
            same($mime, $headers['content-type']);
            same('nosniff', $headers['x-content-type-options']);
            same('private, max-age=0, must-revalidate', $headers['cache-control']);
            same((string) strlen($body), $headers['content-length']);
            same(Spark\Qr\Generator::create('HTTP test')->setFormat($format)->getRawData(), $body);
            $etag = $headers['etag'];
            foreach ([$etag, 'W/' . $etag, '"other", W/' . $etag, '*'] as $validator) {
                [$status, $headers, $body] = $request('GET', $path, $validator);
                same(304, $status);
                same('', $body);
                same($etag, $headers['etag']);
            }
            same(200, $request('GET', $path, '"stale"')[0]);
            same(200, $request('POST', $path, $etag)[0]);
            [$status, $headers, $body] = $request('HEAD', $path);
            same(200, $status);
            same('', $body);
            check((int) $headers['content-length'] > 0, 'HEAD describes the GET body.');
            same(304, $request('HEAD', $path, $etag)[0]);
        }
        same('sent:caught', $request('GET', '/?headers_sent=1')[2]);
    } finally {
        proc_terminate($server);
        proc_close($server);
        fclose($log);
    }
});
