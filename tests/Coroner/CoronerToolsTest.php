<?php

namespace App\Tests\Coroner;

use App\Coroner\Message\ProcessOrder;
use App\Coroner\Mcp\AnalysisService;
use App\Coroner\Mcp\CoronerTools;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Contracts\Service\ServiceProviderInterface;

final class CoronerToolsTest extends TestCase
{
    public function testListFailedMessagesDescribesTheGraveyard(): void
    {
        $tools = $this->createTools([
            $this->createFailedEnvelope(7, new ProcessOrder(3, 'BOOK-ORACLE-001', -2, 'poison@shop.test'), 'Invalid order: quantity must be positive, got -2.'),
            $this->createFailedEnvelope(9, new ProcessOrder(5, 'BOOK-ORACLE-001', 1, 'curie@radium.dev'), 'Inventory API timeout (simulated outage).'),
        ]);

        $report = json_decode($tools->listFailedMessages(10), true);

        $this->assertSame(2, $report['count']);
        $this->assertSame('7', $report['messages'][0]['id']);
        $this->assertSame('App\Coroner\Message\ProcessOrder', $report['messages'][0]['class']);
        $this->assertSame(-2, $report['messages'][0]['payload']['quantity']);
        $this->assertSame('orders', $report['messages'][0]['original_transport']);
    }

    public function testInspectFailedMessageCarriesFullEvidence(): void
    {
        $tools = $this->createTools([
            $this->createFailedEnvelope(7, new ProcessOrder(3, 'BOOK-ORACLE-001', -2, 'poison@shop.test'), 'Invalid order: quantity must be positive, got -2.'),
        ]);

        $evidence = json_decode($tools->inspectFailedMessage('7'), true);

        $this->assertSame(3, $evidence['payload']['order_id']);
        $this->assertSame('Invalid order: quantity must be positive, got -2.', $evidence['exception_message']);
        $this->assertStringContainsString('ProcessOrderHandler', (string) $evidence['handler_source']);
    }

    public function testInspectingAnUnknownIdExplainsItself(): void
    {
        $tools = $this->createTools([]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No failed message with id "999"');

        $tools->inspectFailedMessage('999');
    }

    /**
     * @param list<Envelope> $envelopes
     */
    private function createTools(array $envelopes): CoronerTools
    {
        $receiver = $this->createMock(ListableReceiverInterface::class);
        $receiver->method('all')->willReturnCallback(static fn (int $limit) => \array_slice($envelopes, 0, $limit));
        $receiver->method('find')->willReturnCallback(static function (string $id) use ($envelopes): ?Envelope {
            foreach ($envelopes as $envelope) {
                if ((string) $envelope->last(TransportMessageIdStamp::class)?->getId() === $id) {
                    return $envelope;
                }
            }

            return null;
        });

        $locator = $this->createMock(ServiceProviderInterface::class);
        $locator->method('get')->with('failed')->willReturn($receiver);

        // AnalysisService is final — use the real one over a stub analyst.
        $analyst = $this->createStub(\Symfony\AI\Agent\AgentInterface::class);
        $execution = $this->createStub(\Symfony\AI\Agent\Execution\ExecutionInterface::class);
        $execution->method('getContent')->willReturn('no valid json here');
        $analyst->method('call')->willReturn($execution);
        $analysis = new AnalysisService($analyst);

        return new CoronerTools($locator, $analysis, __DIR__.'/..');
    }

    private function createFailedEnvelope(int $id, ProcessOrder $message, string $error): Envelope
    {
        $throwable = new \RuntimeException($error);

        return new Envelope($message, [
            new TransportMessageIdStamp($id),
            ErrorDetailsStamp::create($throwable),
            new SentToFailureTransportStamp('orders'),
        ]);
    }
}
