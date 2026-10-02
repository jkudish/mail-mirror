<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Tests\Support;

use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use OpenSSLCertificateSigningRequest;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/** A disposable synthetic TLS peer. No external DNS, credentials or provider access. */
final class LocalWriteServer
{
    public readonly string $certificate;

    public readonly string $origin;

    private readonly string $log;

    private ?Process $process = null;

    public function __construct(string $mode)
    {
        $certificate = tempnam(sys_get_temp_dir(), 'mm-wire-cert-');
        $log = tempnam(sys_get_temp_dir(), 'mm-wire-log-');
        if (! is_string($certificate) || ! is_string($log)) {
            throw new RuntimeException('Could not create the synthetic TLS fixture.');
        }
        $this->certificate = $certificate;
        $this->log = $log;

        try {
            $key = openssl_pkey_new(['private_key_bits' => 2048]);
            if (! $key instanceof OpenSSLAsymmetricKey) {
                throw new RuntimeException;
            }
            $signingKey = $key;
            $csr = openssl_csr_new(['commonName' => 'localhost'], $key);
            if (! $csr instanceof OpenSSLCertificateSigningRequest) {
                throw new RuntimeException;
            }
            $cert = openssl_csr_sign($csr, null, $signingKey, 1);
            if (! $cert instanceof OpenSSLCertificate) {
                throw new RuntimeException;
            }
            openssl_x509_export($cert, $pem);
            openssl_pkey_export($signingKey, $privateKey);
            if (! is_string($pem) || ! is_string($privateKey)) {
                throw new RuntimeException;
            }
            file_put_contents($this->certificate, $pem.$privateKey);
            $this->process = new Process([PHP_BINARY, __DIR__.'/resumable-upload-server.php', $this->certificate, $this->log, $mode]);
            $this->process->start();
            $ready = $this->process->waitUntil(fn (): bool => str_contains($this->process->getOutput(), "\n"));
            if (! $ready || preg_match('/PORT (\d+)/', $this->process->getOutput(), $matches) !== 1) {
                throw new RuntimeException;
            }
            $this->origin = 'https://localhost:'.$matches[1];
        } catch (Throwable $failure) {
            $this->close();
            throw new RuntimeException('Could not start the synthetic TLS fixture.', previous: $failure);
        }
    }

    /** @return list<array<array-key, mixed>> */
    public function records(bool $accepted = false): array
    {
        $path = $this->log.($accepted ? '.accepted' : '');
        if (! is_file($path)) {
            return [];
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if (! is_array($lines)) {
            throw new RuntimeException('Missing synthetic wire trace.');
        }

        return array_map(static function (string $line): array {
            $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($row)) {
                throw new RuntimeException('Malformed synthetic wire trace.');
            }

            return $row;
        }, $lines);
    }

    public function close(): void
    {
        $this->process?->stop();
        foreach ([$this->certificate, $this->log, $this->log.'.accepted'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
