<?php

declare(strict_types=1);

namespace Fatturapa\Transport;

use Fatturapa\Contracts\SdiTransport;
use Fatturapa\Exception\TransportException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;

/**
 * HTTP transport for the Openapi.com SdI service.
 *
 * API (https://console.openapi.com/apis/sdi/documentation):
 *   - Base URL: https://sdi.openapi.it (prod), https://test.sdi.openapi.it (sandbox)
 *   - Auth: Bearer token
 *   - POST /invoices        — send invoice XML; returns a uuid
 *   - GET  /invoices/{uuid} — invoice data incl. status
 *
 * Confirm exact payload field names against your Openapi console during onboarding.
 */
class OpenapiClient implements SdiTransport
{
    public const BASE_PRODUCTION = 'https://sdi.openapi.it';
    public const BASE_TEST = 'https://test.sdi.openapi.it';
    private const MAX_ATTEMPTS = 3;
    private const TIMEOUT = 30;

    private Client $http;
    private string $token;
    private string $baseUrl;
    /** @var callable */
    private $logger;
    /** @var callable Sleep function, injectable for tests. */
    private $sleeper;

    public function __construct(
        string $token,
        bool $testMode = true,
        ?Client $http = null,
        ?callable $logger = null,
        ?callable $sleeper = null
    ) {
        $this->token = $token;
        $this->baseUrl = $testMode ? self::BASE_TEST : self::BASE_PRODUCTION;
        $this->http = $http ?: new Client(['timeout' => self::TIMEOUT]);
        $this->logger = $logger ?: fn (string $l, string $m, array $c = []) => null;
        $this->sleeper = $sleeper ?: 'sleep';
    }

    /** Build a client from the OPENAPI_TOKEN environment variable. */
    public static function createFromEnv(bool $testMode = true): self
    {
        $token = getenv('OPENAPI_TOKEN') ?: ($_ENV['OPENAPI_TOKEN'] ?? '');
        if (!$token) {
            throw new TransportException('OPENAPI_TOKEN is not configured');
        }
        return new self($token, $testMode);
    }

    public function sendInvoice(string $xml, array $meta = []): array
    {
        $response = $this->request('POST', '/invoices', [
            'headers' => ['Content-Type' => 'application/xml'],
            'body' => $xml,
        ], $meta);
        $uuid = $response['data']['uuid'] ?? $response['uuid'] ?? null;
        if (!$uuid) {
            throw new TransportException('Openapi response did not contain an invoice uuid: ' . json_encode($response));
        }
        return ['identificativo' => (string) $uuid, 'raw' => $response];
    }

    public function getInvoiceStatus(string $identificativo): array
    {
        $response = $this->request('GET', '/invoices/' . rawurlencode($identificativo), []);
        $status = $response['data']['status'] ?? $response['status'] ?? 'unknown';
        return ['status' => (string) $status, 'raw' => $response];
    }

    /**
     * Perform a request with exponential-backoff retry (max 3 attempts).
     * Retries on network errors and 5xx; 4xx fails immediately.
     *
     * @param array<string, mixed> $options
     * @param array<string, mixed> $logContext
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $options, array $logContext = []): array
    {
        $url = $this->baseUrl . $path;
        $options['headers'] = ($options['headers'] ?? []) + ['Authorization' => 'Bearer ' . $this->token];

        $attempt = 0;
        $lastError = null;
        while (++$attempt <= self::MAX_ATTEMPTS) {
            try {
                $this->log('info', "Openapi request $method $path (attempt $attempt)", $logContext);
                $res = $this->http->request($method, $url, $options);
                $body = (string) $res->getBody();
                $this->log('info', "Openapi response $method $path: HTTP " . $res->getStatusCode(), ['body' => $body]);
                $decoded = json_decode($body, true);
                return is_array($decoded) ? $decoded : ['raw_body' => $body];
            } catch (BadResponseException $e) {
                $status = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
                $body = $e->getResponse() ? (string) $e->getResponse()->getBody() : '';
                $this->log('error', "Openapi error $method $path: HTTP $status", ['body' => $body]);
                if ($status >= 400 && $status < 500) {
                    throw new TransportException("Openapi rejected the request (HTTP $status): $body", $status, $e);
                }
                $lastError = $e;
            } catch (GuzzleException $e) {
                $this->log('error', "Openapi network error $method $path: " . $e->getMessage());
                $lastError = $e;
            }
            if ($attempt < self::MAX_ATTEMPTS) {
                ($this->sleeper)(2 ** $attempt); // 2s, 4s
            }
        }
        throw new TransportException(
            'Openapi request failed after ' . self::MAX_ATTEMPTS . ' attempts: '
            . ($lastError ? $lastError->getMessage() : 'unknown'),
            0,
            $lastError
        );
    }

    /** Log without ever leaking the bearer token. */
    private function log(string $level, string $message, array $context = []): void
    {
        unset($context['headers']);
        $message = str_replace($this->token, '***', $message);
        if (isset($context['body']) && is_string($context['body'])) {
            $context['body'] = substr(str_replace($this->token, '***', $context['body']), 0, 4000);
        }
        ($this->logger)($level, $message, $context);
    }
}
