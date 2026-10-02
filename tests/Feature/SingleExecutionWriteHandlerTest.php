<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Request as NativeRequest;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Jkudish\MailMirror\Exceptions\SyncBudgetExhausted;
use Jkudish\MailMirror\Http\SingleExecutionWriteHandler;
use Jkudish\MailMirror\Read\SyncWorkBudget;
use Jkudish\MailMirror\Tests\Support\LocalWriteServer;
use PHPUnit\Framework\SkippedWithMessageException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Process\Process;

it('preserves refusal statuses without replay or redirects on the real wire', function (int $status): void {
    $server = new LocalWriteServer('status-'.$status);

    try {
        $http = new Factory;
        $http->globalOptions(['verify' => $server->certificate, 'timeout' => 2]);
        $response = SingleExecutionWriteHandler::prepare($http->withBody('Synthetic full body.', 'message/rfc822'))->post($server->origin.'/write');
        expect($response->status())->toBe($status)->and($response->header('Location'))->toBe('/must-not-follow')
            ->and($response->header('Range'))->toBe('bytes=0-7')->and($response->json('id'))->toBe('d-wire')
            ->and($server->records(true))->toHaveCount(1)
            ->and(array_column($server->records(true), 'expect'))->toBe([''])
            ->and(array_column($server->records(), 'sha256'))->toBe([hash('sha256', 'Synthetic full body.')]);
    } finally {
        $server->close();
    }
})->with([307, 401, 417, 500, 503]);

it('bounds slow headers slow body and stalled upload by the full monotonic deadline', function (string $mode): void {
    $server = new LocalWriteServer($mode);
    $timeout = 0.25;
    $body = $mode === 'stalled-upload' ? str_repeat('x', 16 * 1024 * 1024) : 'Synthetic body.';
    $http = new Factory;
    $http->globalOptions(['verify' => $server->certificate]);
    $started = hrtime(true);
    $failed = false;

    try {
        try {
            SingleExecutionWriteHandler::prepare($http->timeout($timeout)->withBody($body, 'message/rfc822'))->post($server->origin.'/write');
        } catch (RuntimeException $failure) {
            expect($failure->getMessage())->toBe('The single-execution provider write transport failed.');
            $failed = true;
        }
        $elapsed = (hrtime(true) - $started) / 1_000_000_000;
        // Each peer stalls/drips for 8s, beyond the 0.25s + 5s allowance;
        // completing the peer without a total deadline must fail this assertion.
        expect($elapsed)->toBeLessThan($timeout + 5)->toBeGreaterThan(0.1)
            ->and($failed)->toBeTrue()
            ->and($server->records(true))->toHaveCount(1);
    } finally {
        $server->close();
    }
})->with(['slow-headers', 'slow-body', 'stalled-upload']);

it('refuses a second base handler invocation before a second connection', function (): void {
    $server = new LocalWriteServer('echo');
    $handler = new SingleExecutionWriteHandler;
    $request = new NativeRequest('POST', $server->origin.'/write', [], 'Single body.');
    $options = ['timeout' => 2, 'verify' => $server->certificate];

    try {
        $response = $handler($request, $options)->wait();
        if (! $response instanceof ResponseInterface) {
            throw new RuntimeException('The transport did not return a response.');
        }
        expect($response->getStatusCode())->toBe(200);
        expect(fn () => $handler($request, $options)->wait())->toThrow(RuntimeException::class, 'already used')
            ->and($server->records(true))->toHaveCount(1)
            ->and($request->getBody()->tell())->toBe(12)
            ->and(array_column($server->records(), 'length'))->toBe([12]);
    } finally {
        $server->close();
    }
});

it('preserves informational and final headers stats and budget progress callbacks', function (): void {
    $server = new LocalWriteServer('informational');
    $stats = null;
    $progressCalls = 0;
    $http = new Factory;
    $http->globalOptions(['verify' => $server->certificate]);

    try {
        $response = SingleExecutionWriteHandler::prepare($http->timeout(2)->withBody('Body.', 'message/rfc822')->withOptions([
            'on_stats' => function (TransferStats $observed) use (&$stats): void {
                $stats = $observed;
            },
            'progress' => function () use (&$progressCalls): void {
                $progressCalls++;
            },
        ]))->post($server->origin.'/write');
        expect($response->status())->toBe(201)->and($response->header('Location'))->toBe('/must-not-follow')
            ->and($response->header('Range'))->toBe('bytes=0-7')->and($response->json())->toBe(['id' => 'd-wire'])
            ->and($progressCalls)->toBeGreaterThan(0);
        expect($stats)->toBeInstanceOf(TransferStats::class);
        if (! $stats instanceof TransferStats) {
            throw new RuntimeException('Missing transport stats.');
        }
        expect($stats->getHandlerStats()['informational_headers'])->toBe([['X-Interim' => ['synthetic']], ['Link' => ['</synthetic>']]]);

        $budget = new SyncWorkBudget(maxDownloadedBytes: 4);
        $failedStatsCalled = false;
        try {
            SingleExecutionWriteHandler::prepare($http->timeout(2)->withBody('Body.', 'message/rfc822')->withOptions([
                'progress' => function (int $total, int $downloaded) use ($budget): void {
                    if ($downloaded > $budget->remainingDownloadedBytes()) {
                        $budget->recordDownloadedBytes($downloaded);
                    }
                },
                'on_stats' => function () use (&$failedStatsCalled): void {
                    $failedStatsCalled = true;
                    throw new RuntimeException('Synthetic stats failure after budget failure.');
                },
            ]))->post($server->origin.'/write');
            throw new LogicException('The byte budget was ignored.');
        } catch (SyncBudgetExhausted $failure) {
            expect($failure->dimension)->toBe('downloaded_bytes')->and($failure->getPrevious())->toBeNull();
        }
        expect($server->records(true))->toHaveCount(2)->and($failedStatsCalled)->toBeTrue();
    } finally {
        $server->close();
    }
});

it('keeps fakes recording and request middleware around the selected base handler', function (): void {
    $http = new Factory;
    $http->fake(fn (Request $request) => Http::response(['id' => $request->header('X-Synthetic')[0]]));
    $http->globalRequestMiddleware(fn (RequestInterface $request): RequestInterface => $request->withHeader('X-Synthetic', 'recorded'));

    $response = SingleExecutionWriteHandler::prepare($http->timeout(1))->post('https://synthetic.invalid/write', ['key' => 'value']);
    expect($response->json('id'))->toBe('recorded')->and($http->recorded())->toHaveCount(1);
    $http->assertSent(fn (Request $request): bool => $request->data() === ['key' => 'value']);
});

it('sends the complete body once after body-inspecting middleware on real TLS', function (string $inspection): void {
    $server = new LocalWriteServer('echo');
    $body = 'Synthetic middleware-read payload.';
    $inspected = null;
    $http = new Factory;
    $http->globalOptions(['verify' => $server->certificate]);

    try {
        $pending = $http->timeout(2)->withBody($body, 'message/rfc822');
        if ($inspection === 'beforeSending') {
            $pending->beforeSending(function (Request $request) use (&$inspected): void {
                $inspected = $request->body();
            });
        } else {
            $pending->withRequestMiddleware(function (RequestInterface $request) use (&$inspected): RequestInterface {
                $inspected = (string) $request->getBody();

                return $request;
            });
        }
        $response = SingleExecutionWriteHandler::prepare($pending)->post($server->origin.'/write');
        expect($response->status())->toBe(200)->and($inspected)->toBe($body)
            ->and($server->records(true))->toHaveCount(1)
            ->and(array_column($server->records(), 'length'))->toBe([34])
            ->and(array_column($server->records(), 'sha256'))->toBe([hash('sha256', $body)]);
    } finally {
        $server->close();
    }
})->with(['beforeSending', 'PSR string inspection']);

it('refuses an advanced nonseekable body before connection', function (): void {
    $server = new LocalWriteServer('echo');
    $body = new NoSeekStream(Utils::streamFor('Nonseekable bytes.'));
    $body->read(4);
    $request = new NativeRequest('POST', $server->origin.'/write', [], $body);

    try {
        expect(fn () => (new SingleExecutionWriteHandler)($request, ['timeout' => 2, 'verify' => $server->certificate])->wait())
            ->toThrow(RuntimeException::class, 'single-execution')
            ->and($server->records(true))->toBe([]);
    } finally {
        $server->close();
    }
});

it('accepts TLS 1.2 and 1.3 while preserving the fixed minimum', function (string $version): void {
    $server = new LocalWriteServer('tls-'.$version);
    $http = new Factory;
    $http->globalOptions(['verify' => $server->certificate]);

    try {
        $response = SingleExecutionWriteHandler::prepare($http->timeout(2)->withBody('TLS body.', 'message/rfc822'))
            ->post($server->origin.'/write');
        expect($response->status())->toBe(200)->and($server->records(true))->toHaveCount(1)
            ->and(array_column($server->records(true), 'tls'))->toBe(['TLSv'.$version])
            ->and(array_column($server->records(), 'sha256'))->toBe([hash('sha256', 'TLS body.')]);
    } finally {
        $server->close();
    }
})->with(['1.2', '1.3']);

it('refuses a TLS 1.1-only peer that a synthetic legacy qualification can reach', function (): void {
    $server = new LocalWriteServer('tls-1.1');

    try {
        // Qualify the peer explicitly; this legacy override is never accepted by
        // the package. Some runtimes compile out TLS 1.1 entirely.
        $legacy = curl_init($server->origin.'/qualification');
        if (! $legacy instanceof CurlHandle) {
            throw new RuntimeException('Could not start the local TLS qualification.');
        }
        assert($server->certificate !== '');
        curl_setopt_array($legacy, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => 2000,
            CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_CAINFO => $server->certificate,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_1 | CURL_SSLVERSION_MAX_TLSv1_1,
            CURLOPT_SSL_CIPHER_LIST => 'DEFAULT:@SECLEVEL=0']);
        $supported = curl_exec($legacy) !== false;
        unset($legacy);
        if (! $supported) {
            throw new SkippedWithMessageException('This runtime cannot qualify the local legacy TLS 1.1 peer.');
        }
        expect(array_column($server->records(true), 'tls'))->toBe(['TLSv1.1']);

        $http = new Factory;
        $http->globalOptions(['verify' => $server->certificate]);
        expect(fn () => SingleExecutionWriteHandler::prepare($http->timeout(2)->withBody('Must not send.', 'message/rfc822'))
            ->post($server->origin.'/write'))->toThrow(RuntimeException::class, 'single-execution')
            ->and($server->records(true))->toHaveCount(1);
    } finally {
        $server->close();
    }
});

it('rejects unsupported overrides and unsafe destinations before connecting', function (array $overrides, string $url): void {
    $server = new LocalWriteServer('echo');
    $request = new NativeRequest('POST', $url === 'safe' ? $server->origin.'/write' : $url, [], 'Private synthetic body.');
    $options = ['timeout' => 1, 'verify' => $server->certificate];
    foreach ($overrides as $key => $value) {
        if (! is_string($key)) {
            throw new LogicException('Fixture options must have string keys.');
        }
        $options[$key] = $value;
    }

    try {
        expect(fn () => (new SingleExecutionWriteHandler)($request, $options)->wait())
            ->toThrow(RuntimeException::class, 'single-execution')
            ->and($server->records(true))->toBe([]);
    } finally {
        $server->close();
    }
})->with([
    'arbitrary curl' => [['curl' => [CURLOPT_FOLLOWLOCATION => true]], 'safe'],
    'proxy' => [['proxy' => 'https://synthetic.invalid'], 'safe'],
    'unverified TLS' => [['verify' => false], 'safe'],
    'redirect' => [['allow_redirects' => true], 'safe'],
    'alternate crypto method' => [['crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT], 'safe'],
    'zero deadline' => [['timeout' => 0], 'safe'],
    'debug' => [['debug' => true], 'safe'],
    'sink' => [['sink' => '/tmp/must-not-write'], 'safe'],
    'user info' => [[], 'https://CAPABILITY@localhost/write'],
    'plain HTTP' => [[], 'http://localhost/write'],
]);

it('refuses before network when ext-cURL is unavailable', function (): void {
    // This extension-refusal proof needs class loading only, not framework
    // bootstrap functions whose legacy lowest versions require other extensions.
    $code = '$vendor = '.var_export(dirname(__DIR__, 2).'/vendor', true).';
        require $vendor."/composer/ClassLoader.php";
        $loader = new Composer\\Autoload\\ClassLoader();
        foreach (require $vendor."/composer/autoload_psr4.php" as $prefix => $paths) { $loader->addPsr4($prefix, $paths); }
        $loader->addClassMap(require $vendor."/composer/autoload_classmap.php");
        $loader->register();
        try { (new Jkudish\\MailMirror\\Http\\SingleExecutionWriteHandler)(new GuzzleHttp\\Psr7\\Request("POST", "https://localhost/write"), ["timeout" => 1])->wait(); exit(1); }
        catch (RuntimeException $failure) { echo $failure->getMessage(); }';
    $process = new Process([PHP_BINARY, '-n', '-r', $code]);
    $process->run();
    expect($process->getExitCode())->toBe(0)->and($process->getOutput())->toBe('The single-execution provider write transport failed.');
});
