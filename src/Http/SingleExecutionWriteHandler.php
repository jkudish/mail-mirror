<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Http;

use CurlHandle;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\PendingRequest;
use Jkudish\MailMirror\Exceptions\SyncBudgetExhausted;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Throwable;

/** @internal Only the provider-write profile; not a general HTTP client. One instance per logical dispatch. */
final class SingleExecutionWriteHandler
{
    private bool $invoked = false;

    public static function prepare(#[\SensitiveParameter] PendingRequest $request): PendingRequest
    {
        self::assertAvailable();
        // Set the base handler so Laravel's fake, recording and middleware stack
        // survives. Disable implicit environment proxies before Guzzle merges them.
        $options = $request->getOptions();
        if (isset($options['handler'])) {
            throw new RuntimeException('Unsupported provider write transport options.');
        }

        return $request->setHandler(new self)->withoutRedirecting()->retry(1)
            ->withOptions(['proxy' => $options['proxy'] ?? '', 'expect' => false]);
    }

    /** @param array<string, mixed> $options */
    public function __invoke(#[\SensitiveParameter] RequestInterface $request, #[\SensitiveParameter] array $options): PromiseInterface
    {
        if ($this->invoked) {
            return Create::rejectionFor(new RuntimeException('The provider write transport was already used.'));
        }
        $this->invoked = true;
        $started = hrtime(true);

        try {
            $this->validate($request, $options);
            $timeout = $options['timeout'];
            if ((! is_int($timeout) && ! is_float($timeout)) || ! is_finite((float) $timeout) || $timeout <= 0 || $timeout > 300) {
                throw new RuntimeException;
            }
            $deadline = $started + (int) floor($timeout * 1_000_000_000);
            $body = $request->getBody();
            $size = $body->getSize();
            if ($size === null || $size < 0 || $body->tell() !== 0) {
                throw new RuntimeException;
            }

            $headers = ['Expect:', 'Connection: close'];
            foreach ($request->getHeaders() as $name => $values) {
                if (in_array(strtolower($name), ['expect', 'connection'], true)) {
                    continue;
                }
                if (strtolower($name) === 'transfer-encoding'
                    || (strtolower($name) === 'content-length' && $values !== [(string) $size])) {
                    throw new RuntimeException;
                }
                foreach ($values as $value) {
                    $headers[] = $name.': '.$value;
                }
            }

            $handle = curl_init();
            if (! $handle instanceof CurlHandle) {
                throw new RuntimeException;
            }
            $sent = 0;
            $received = '';
            $status = 0;
            $reason = '';
            $responseHeaders = [];
            $informational = [];
            $aborted = null;
            $progress = $options['progress'] ?? null;
            $onHeaders = $options['on_headers'] ?? null;
            $native = [
                CURLOPT_URL => (string) $request->getUri(),
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_FRESH_CONNECT => true, CURLOPT_FORBID_REUSE => true,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
                CURLOPT_HTTPAUTH => CURLAUTH_NONE, CURLOPT_PROXYAUTH => CURLAUTH_NONE,
                CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_NOSIGNAL => true,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_SSL_SESSIONID_CACHE => false, CURLOPT_SSL_OPTIONS => 0,
                CURLOPT_TCP_FASTOPEN => false,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_UPLOAD => true, CURLOPT_INFILESIZE_LARGE => $size,
                CURLOPT_CUSTOMREQUEST => $request->getMethod(),
                CURLOPT_ENCODING => ($options['decode_content'] ?? true) === false ? null : '',
                CURLOPT_NOPROGRESS => false,
                CURLOPT_READFUNCTION => static function (CurlHandle $handle, mixed $stream, int $length) use ($body, $size, &$sent, &$aborted): string {
                    try {
                        if ($sent >= $size) {
                            return '';
                        }
                        $chunk = $body->read(min($length, $size - $sent));
                        if ($chunk === '') {
                            throw new RuntimeException;
                        }
                        $sent += strlen($chunk);

                        return $chunk;
                    } catch (Throwable $failure) {
                        $aborted = $failure;

                        // PHP exposes neither CURL_READFUNC_ABORT nor SEEKFUNCTION.
                        // Stop feeding; the progress callback aborts this execution.
                        return '';
                    }
                },
                CURLOPT_WRITEFUNCTION => static function (CurlHandle $handle, string $chunk) use (&$received, $progress, $size, &$sent, &$aborted): int {
                    try {
                        if (is_callable($progress)) {
                            $progress(0, strlen($received) + strlen($chunk), $size, $sent);
                        }
                        $received .= $chunk;

                        return strlen($chunk);
                    } catch (Throwable $failure) {
                        $aborted = $failure;

                        return 0;
                    }
                },
                CURLOPT_XFERINFOFUNCTION => static function (CurlHandle $handle, float $total, float $downloaded, float $uploadTotal, float $uploaded) use ($progress, &$received, &$aborted): int {
                    try {
                        if ($aborted !== null) {
                            return 1;
                        }
                        if (is_callable($progress)) {
                            $progress((int) $total, max((int) $downloaded, strlen($received)), (int) $uploadTotal, (int) $uploaded);
                        }

                        return 0;
                    } catch (Throwable $failure) {
                        $aborted = $failure;

                        return 1;
                    }
                },
                CURLOPT_HEADERFUNCTION => static function (CurlHandle $handle, string $line) use (&$status, &$reason, &$responseHeaders, &$informational, $onHeaders, &$aborted): int {
                    try {
                        if (preg_match('/\AHTTP\/1\.[01] (\d{3})(?: (.*))?\r\n\z/', $line, $matches) === 1) {
                            $status = (int) $matches[1];
                            $reason = $matches[2] ?? '';
                            $responseHeaders = [];
                        } elseif ($line === "\r\n") {
                            if ($status < 200) {
                                $informational[] = $responseHeaders;
                            } elseif (is_callable($onHeaders)) {
                                $onHeaders(new Response($status, $responseHeaders, '', '1.1', $reason));
                            }
                        } else {
                            $parts = explode(':', trim($line), 2);
                            if (count($parts) !== 2) {
                                throw new RuntimeException;
                            }
                            $responseHeaders[trim($parts[0])][] = trim($parts[1]);
                        }

                        return strlen($line);
                    } catch (Throwable $failure) {
                        $aborted = $failure;

                        return 0;
                    }
                },
            ];
            if (is_string($options['verify'] ?? null)) {
                $native[CURLOPT_CAINFO] = $options['verify'];
            }
            $remainingMs = (int) floor(($deadline - hrtime(true)) / 1_000_000);
            if ($remainingMs < 1) {
                throw new RuntimeException;
            }
            $native[CURLOPT_TIMEOUT_MS] = $remainingMs;
            $connect = $options['connect_timeout'] ?? $timeout;
            if ((! is_int($connect) && ! is_float($connect)) || ! is_finite((float) $connect) || $connect <= 0) {
                throw new RuntimeException;
            }
            $native[CURLOPT_CONNECTTIMEOUT_MS] = min($remainingMs, max(1, (int) floor($connect * 1000)));
            if (! curl_setopt_array($handle, $native)) {
                throw new RuntimeException;
            }

            // The sole execution. No reset, seek, POSTFIELDS, retry, or shared easy handle.
            $ok = curl_exec($handle);
            $errno = curl_errno($handle);
            $response = $ok !== false && $status >= 200 && $aborted === null
                ? new Response($status, $responseHeaders, $received, '1.1', $reason) : null;
            unset($handle);
            if (is_callable($options['on_stats'] ?? null)) {
                $options['on_stats'](new TransferStats($request, $response, (hrtime(true) - $started) / 1_000_000_000,
                    $errno, ['informational_headers' => $informational]));
            }
            if ($aborted instanceof SyncBudgetExhausted) {
                throw new SyncBudgetExhausted($aborted->dimension, $aborted->snapshot);
            }
            if ($response === null) {
                throw new RuntimeException;
            }

            return Create::promiseFor($response);
        } catch (Throwable $failure) {
            return Create::rejectionFor($failure instanceof SyncBudgetExhausted
                ? new SyncBudgetExhausted($failure->dimension, $failure->snapshot)
                : new RuntimeException('The single-execution provider write transport failed.'));
        }
    }

    /** @param array<string, mixed> $options */
    private function validate(#[\SensitiveParameter] RequestInterface $request, #[\SensitiveParameter] array $options): void
    {
        self::assertAvailable();
        if ($request->getUri()->getScheme() !== 'https' || $request->getUri()->getUserInfo() !== ''
            || $request->getUri()->getFragment() !== '' || $request->getProtocolVersion() !== '1.1'
            || ! in_array($request->getMethod(), ['POST', 'PUT', 'DELETE'], true)) {
            throw new RuntimeException;
        }
        $allowed = ['handler', 'timeout', 'connect_timeout', 'verify', 'proxy', 'expect', 'allow_redirects',
            'http_errors', 'headers', 'body', 'json', 'query', 'laravel_data', 'cookies', 'version', 'decode_content',
            'idn_conversion', 'crypto_method', 'synchronous', 'progress', 'on_stats', 'on_headers', 'protocols',
            'request_factory', 'uri_factory', 'stream_factory', 'response_factory'];
        if (array_diff(array_keys($options), $allowed) !== [] || ! isset($options['timeout'])
            || ! in_array($options['proxy'] ?? '', ['', false, null], true)
            || ($options['allow_redirects'] ?? false) !== false || ($options['http_errors'] ?? false) !== false
            || ($options['expect'] ?? false) !== false || ($options['version'] ?? '1.1') !== '1.1'
            || ! is_bool($options['decode_content'] ?? true)
            || (($options['verify'] ?? true) !== true && ! is_string($options['verify'] ?? null))
            || (isset($options['crypto_method']) && $options['crypto_method'] !== STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT)
            || (isset($options['handler']) && ! $options['handler'] instanceof HandlerStack)) {
            throw new RuntimeException;
        }
        foreach (['request_factory', 'uri_factory', 'stream_factory', 'response_factory'] as $factory) {
            // Guzzle 8 supplies these final factory objects; Guzzle 7 does not.
            // Compare the loaded object's type without loading an unused factory
            // and its unrelated UploadedFile interface on lowest dependencies.
            if (isset($options[$factory]) && (! is_object($options[$factory])
                || $options[$factory]::class !== 'GuzzleHttp\\Psr7\\HttpFactory')) {
                throw new RuntimeException;
            }
        }
        if (isset($options['protocols']) && ! in_array($options['protocols'], [['http', 'https'], ['https']], true)) {
            throw new RuntimeException;
        }
        foreach (['progress', 'on_stats', 'on_headers'] as $callback) {
            if (isset($options[$callback]) && ! is_callable($options[$callback])) {
                throw new RuntimeException;
            }
        }
    }

    private static function assertAvailable(): void
    {
        $version = extension_loaded('curl') ? curl_version() : false;
        if (! is_array($version) || ! is_int($version['features'] ?? null) || ($version['features'] & CURL_VERSION_ASYNCHDNS) === 0) {
            throw new RuntimeException('Provider writes require ext-cURL with asynchronous DNS.');
        }
    }
}
