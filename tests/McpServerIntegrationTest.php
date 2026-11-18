<?php

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * End-to-end handshake with the real MCP server over STDIO — the same
 * channel Claude Code or any other MCP client would use.
 */
final class McpServerIntegrationTest extends TestCase
{
    /** @var resource */
    private $server;

    private array $pipes = [];

    protected function tearDown(): void
    {
        fclose($this->pipes[0]);
        proc_terminate($this->server);
    }

    public function testServerHandshakeAndToolListing(): void
    {
        $this->startServer();

        $this->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-11-25',
            'capabilities' => [],
            'clientInfo' => ['name' => 'phpunit', 'version' => '0.0.1'],
        ]]);

        $initialize = $this->readResponse(1);
        $this->assertSame('coroner', $initialize['result']['serverInfo']['name']);
        $this->assertSame('2025-11-25', $initialize['result']['protocolVersion']);
        $this->assertStringContainsString('forensics', $initialize['result']['instructions']);

        $this->send(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

        $this->send(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []]);
        $tools = $this->readResponse(2);

        $names = array_column($tools['result']['tools'], 'name');
        $this->assertSame([
            'list_failed_messages',
            'inspect_failed_message',
            'analyze_failed_message',
            'retry_failed_message',
            'discard_failed_message',
        ], $names);
    }

    public function testListFailedMessagesSpeaksToTheRealTransport(): void
    {
        // Seed a graveyard first — the command is self-healing about the DB.
        (new Process([PHP_BINARY, __DIR__.'/../bin/console', 'coroner:demo:break'], __DIR__.'/..'))
            ->setTimeout(180)
            ->mustRun();

        $this->startServer();
        $this->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-11-25',
            'capabilities' => [],
            'clientInfo' => ['name' => 'phpunit', 'version' => '0.0.1'],
        ]]);
        $this->readResponse(1);
        $this->send(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

        $this->send(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => [
            'name' => 'list_failed_messages',
            'arguments' => ['limit' => 50],
        ]]);
        $result = $this->readResponse(2);

        $report = json_decode($result['result']['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertGreaterThanOrEqual(5, $report['count']);

        foreach ($report['messages'] as $message) {
            $this->assertSame('App\Coroner\Message\ProcessOrder', $message['class']);
            $this->assertArrayHasKey('payload', $message);
            $this->assertArrayHasKey('error', $message);
        }
    }

    private function startServer(): void
    {
        $this->server = proc_open(
            [PHP_BINARY, __DIR__.'/../bin/console', 'mcp:server', 'coroner'],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'e']],
            $this->pipes,
            __DIR__.'/..',
        );
        $this->assertIsResource($this->server, 'Failed to start mcp:server: '.stream_get_contents($this->pipes[2]));
    }

    private function send(array $payload): void
    {
        fwrite($this->pipes[0], json_encode($payload)."\n");
    }

    private function readResponse(int $id): array
    {
        $deadline = microtime(true) + 30;
        while (microtime(true) < $deadline) {
            $line = fgets($this->pipes[1]);
            if (false === $line) {
                $this->fail('Server closed stdout: '.stream_get_contents($this->pipes[2]));
            }
            $message = json_decode(trim($line), true);
            if (\is_array($message) && ($message['id'] ?? null) === $id) {
                if (isset($message['error'])) {
                    $this->fail('MCP error: '.json_encode($message['error']));
                }

                return $message;
            }
        }

        $this->fail('Timed out waiting for response to id '.$id);
    }
}
