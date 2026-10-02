<?php

declare(strict_types=1);

// Local TLS peer for the real-wire resumable regression. Never connects outward.
assert(isset($argv[1], $argv[2], $argv[3]));
$crypto = match ($argv[3]) {
    'tls-1.1' => STREAM_CRYPTO_METHOD_TLSv1_1_SERVER,
    'tls-1.2' => STREAM_CRYPTO_METHOD_TLSv1_2_SERVER,
    'tls-1.3' => STREAM_CRYPTO_METHOD_TLSv1_3_SERVER,
    default => STREAM_CRYPTO_METHOD_TLS_SERVER,
};
$context = stream_context_create(['ssl' => ['local_cert' => $argv[1], 'verify_peer' => false,
    'crypto_method' => $crypto, 'security_level' => $argv[3] === 'tls-1.1' ? 0 : 2]]);
$server = stream_socket_server('tls://127.0.0.1:0', context: $context);
assert(is_resource($server));
$address = stream_socket_get_name($server, false);
assert(is_string($address));
echo 'PORT '.substr($address, strrpos($address, ':') + 1)."\n";
flush();
$uploaded = false;

while ($peer = stream_socket_accept($server, 10)) {
    stream_set_timeout($peer, 5);
    $line = fgets($peer);
    assert(is_string($line));
    $headers = [];

    while (($header = fgets($peer)) !== false && $header !== "\r\n") {
        $parts = explode(':', $header, 2);
        assert(count($parts) === 2);
        $headers[strtolower($parts[0])] = trim($parts[1]);
    }

    $cryptoInfo = stream_get_meta_data($peer)['crypto'] ?? [];
    file_put_contents($argv[2].'.accepted', json_encode(['line' => trim($line), 'expect' => $headers['expect'] ?? '', 'tls' => $cryptoInfo['protocol'] ?? null,
        'length' => (int) ($headers['content-length'] ?? 0)], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
    if ($argv[3] === 'stalled-upload') {
        sleep(8);
        fclose($peer);

        continue;
    }

    $length = (int) ($headers['content-length'] ?? 0);
    $body = '';
    $remaining = $length;

    while ($remaining > 0) {
        $chunk = fread($peer, $remaining);
        assert(is_string($chunk) && $chunk !== '');
        $body .= $chunk;
        $remaining -= strlen($chunk);
    }

    file_put_contents($argv[2], json_encode(['line' => trim($line), 'range' => $headers['content-range'] ?? null,
        'length' => strlen($body), 'sha256' => hash('sha256', $body)], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);

    if ($argv[3] === 'slow-headers') {
        fwrite($peer, "HTTP/1.1 200 OK\r\nX-Drip: ");
        for ($i = 0; $i < 160; $i++) {
            fwrite($peer, 'x');
            usleep(50000);
        }
        fwrite($peer, "\r\nContent-Length: 0\r\n\r\n");
    } elseif ($argv[3] === 'slow-body') {
        fwrite($peer, "HTTP/1.1 200 OK\r\nContent-Length: 160\r\n\r\n");
        for ($i = 0; $i < 160; $i++) {
            fwrite($peer, 'x');
            usleep(50000);
        }
    } elseif (str_starts_with($argv[3], 'status-') || str_starts_with($argv[3], 'tls-') || in_array($argv[3], ['echo', 'informational'], true)) {
        $status = str_starts_with($argv[3], 'status-') ? (int) substr($argv[3], 7) : ($argv[3] === 'informational' ? 201 : 200);
        if ($argv[3] === 'informational') {
            fwrite($peer, "HTTP/1.1 100 Continue\r\nX-Interim: synthetic\r\n\r\nHTTP/1.1 103 Early Hints\r\nLink: </synthetic>\r\n\r\n");
        }
        $response = '{"id":"d-wire"}';
        fwrite($peer, 'HTTP/1.1 '.$status." Synthetic\r\nLocation: /must-not-follow\r\nRange: bytes=0-7\r\nContent-Type: application/json\r\nContent-Length: ".strlen($response)."\r\nConnection: close\r\n\r\n".$response);
    } elseif ($length > 0) {
        $uploaded = true;

        if ($argv[3] === 'redirect') {
            fwrite($peer, "HTTP/1.1 307 Temporary Redirect\r\nLocation: /must-not-follow\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
        }
        // The entire MIME was accepted; close without any response in 'close' mode.
    } elseif ($uploaded) {
        $response = '{"id":"d-wire"}';
        fwrite($peer, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: ".strlen($response)."\r\nConnection: close\r\n\r\n".$response);
    } else {
        fwrite($peer, "HTTP/1.1 308 Resume Incomplete\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
    }

    fclose($peer);
}

fclose($server);
