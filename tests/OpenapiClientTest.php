<?php

declare(strict_types=1);

namespace Fatturapa\Tests;

use Fatturapa\Exception\TransportException;
use Fatturapa\Transport\OpenapiClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class OpenapiClientTest extends TestCase
{
    /** @var array<array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    private function client(MockHandler $mock, ?callable $logger = null): OpenapiClient
    {
        $stack = HandlerStack::create($mock);
        $this->history = [];
        $stack->push(Middleware::history($this->history));
        return new OpenapiClient(
            'secret-token',
            testMode: true,
            http: new Client(['handler' => $stack]),
            logger: $logger,
            sleeper: static function (): void {
            },
        );
    }

    public function testSendInvoiceReturnsUuidAndSendsBearer(): void
    {
        $client = $this->client(new MockHandler([
            new Response(200, [], (string) json_encode(['data' => ['uuid' => 'abc-123']])),
        ]));

        $result = $client->sendInvoice('<xml/>');

        $this->assertSame('abc-123', $result['identificativo']);
        $request = $this->history[0]['request'];
        $this->assertSame('Bearer secret-token', $request->getHeaderLine('Authorization'));
        $this->assertSame('application/xml', $request->getHeaderLine('Content-Type'));
    }

    public function testRetriesOn5xxThenSucceeds(): void
    {
        $client = $this->client(new MockHandler([
            new Response(500, [], 'boom'),
            new Response(200, [], (string) json_encode(['uuid' => 'after-retry'])),
        ]));

        $result = $client->sendInvoice('<xml/>');
        $this->assertSame('after-retry', $result['identificativo']);
        $this->assertCount(2, $this->history);
    }

    public function test4xxFailsImmediatelyWithoutRetry(): void
    {
        $client = $this->client(new MockHandler([
            new Response(422, [], 'invalid xml'),
        ]));

        try {
            $client->sendInvoice('<xml/>');
            $this->fail('expected TransportException');
        } catch (TransportException $e) {
            $this->assertStringContainsString('422', $e->getMessage());
        }
        $this->assertCount(1, $this->history);
    }

    public function testMissingUuidIsAnError(): void
    {
        $client = $this->client(new MockHandler([
            new Response(200, [], (string) json_encode(['data' => []])),
        ]));
        $this->expectException(TransportException::class);
        $client->sendInvoice('<xml/>');
    }

    public function testLoggerNeverSeesTheToken(): void
    {
        $lines = [];
        $client = $this->client(
            new MockHandler([
                new Response(200, [], (string) json_encode(['uuid' => 'u1', 'echo' => 'secret-token'])),
            ]),
            function (string $level, string $message, array $context = []) use (&$lines): void {
                $lines[] = $message . ' ' . json_encode($context);
            }
        );

        $client->sendInvoice('<xml/>');

        $this->assertNotEmpty($lines);
        foreach ($lines as $line) {
            $this->assertStringNotContainsString('secret-token', $line);
        }
    }
}
