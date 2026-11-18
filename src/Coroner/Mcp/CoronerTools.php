<?php

namespace App\Coroner\Mcp;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Elicitation\BooleanSchemaDefinition;
use Mcp\Schema\Elicitation\ElicitationSchema;
use Mcp\Schema\Elicitation\StringSchemaDefinition;
use Mcp\Schema\Result\ElicitResult;
use Mcp\Server\RequestContext;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Process\Process;
use Symfony\Contracts\Service\ServiceProviderInterface;

/**
 * The Coroner MCP server: five tools that an MCP client (Claude Code,
 * Cursor, coroner:chat's own agent...) uses to investigate Symfony
 * Messenger's failure transport.
 *
 * list / inspect / analyze are read-only.
 * retry / discard are destructive: they ALWAYS ask the human operator
 * first, via MCP elicitation — the server refuses to act unilaterally.
 */
final class CoronerTools
{
    private const FAILURE_TRANSPORT = 'failed';

    public function __construct(
        #[Autowire(service: 'messenger.receiver_locator')]
        private readonly ServiceProviderInterface $receiverLocator,
        private readonly AnalysisService $analysis,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {
    }

    #[McpTool(
        name: 'list_failed_messages',
        title: 'List failed Messenger messages',
        description: 'Lists messages sitting in the Symfony Messenger failure transport (the graveyard). Read-only and safe. Returns id, class, payload summary, error and failure time for each.',
    )]
    public function listFailedMessages(int $limit = 20): string
    {
        $rows = [];
        foreach ($this->failureReceiver()->all($limit) as $envelope) {
            $rows[] = [
                'id' => (string) $envelope->last(TransportMessageIdStamp::class)?->getId(),
                'class' => $envelope->getMessage()::class,
                'payload' => json_decode($this->json($envelope->getMessage()), true),
                'error' => $this->firstErrorLine($envelope),
                'retries' => \count($envelope->all(RedeliveryStamp::class)),
                'failed_at' => $envelope->last(RedeliveryStamp::class)?->getRedeliveredAt()?->format(\DateTimeInterface::ATOM),
                'original_transport' => $envelope->last(SentToFailureTransportStamp::class)?->getOriginalReceiverName(),
            ];
        }

        return $this->json(['count' => \count($rows), 'messages' => $rows]);
    }

    #[McpTool(
        name: 'inspect_failed_message',
        title: 'Inspect one failed message',
        description: 'Opens one failed Messenger message: full payload, exception class and message, stack trace, retry count and original transport. Read-only and safe.',
    )]
    public function inspectFailedMessage(string $id): string
    {
        $envelope = $this->envelope($id);
        $message = $envelope->getMessage();
        $error = $envelope->last(ErrorDetailsStamp::class);

        return $this->json([
            'id' => (string) $id,
            'class' => $message::class,
            'payload' => json_decode($this->json($message), true),
            'exception_class' => $error?->getExceptionClass(),
            'exception_message' => $error?->getExceptionMessage(),
            'exception_code' => $error?->getExceptionCode(),
            'stack_trace' => $this->stackTrace($error),
            'retries' => \count($envelope->all(RedeliveryStamp::class)),
            'original_transport' => $envelope->last(SentToFailureTransportStamp::class)?->getOriginalReceiverName(),
            'handler_source' => $this->handlerSource($message::class),
        ]);
    }

    #[McpTool(
        name: 'analyze_failed_message',
        title: 'Analyze a failed message with AI',
        description: 'Runs an AI pathologist over the evidence of one failed message (payload, exception, stack trace, handler source) and returns a structured diagnosis: failure_class (transient|bug|poison), root cause, recommended action (retry|fix_then_retry|discard) and fix suggestion. Read-only and safe.',
    )]
    public function analyzeFailedMessage(string $id): string
    {
        $envelope = $this->envelope($id);
        $message = $envelope->getMessage();
        $error = $envelope->last(ErrorDetailsStamp::class);

        $evidence = [
            'message_class' => $message::class,
            'payload' => json_decode($this->json($message), true),
            'exception_class' => $error?->getExceptionClass(),
            'exception_message' => $error?->getExceptionMessage(),
            'exception_code' => $error?->getExceptionCode(),
            'stack_trace' => $this->stackTrace($error),
            'retries' => \count($envelope->all(RedeliveryStamp::class)),
            'original_transport' => $envelope->last(SentToFailureTransportStamp::class)?->getOriginalReceiverName(),
            'handler_source' => $this->handlerSource($message::class),
        ];

        return $this->json(['id' => (string) $id] + $this->analysis->diagnose($evidence));
    }

    #[McpTool(
        name: 'retry_failed_message',
        title: 'Retry a failed message (asks the human)',
        description: 'Re-queues one failed Messenger message for immediate execution. DESTRUCTIVE: always asks the human operator for approval via MCP elicitation first, and returns "declined" when they refuse.',
    )]
    public function retryFailedMessage(string $id, RequestContext $context): string
    {
        $envelope = $this->envelope($id);
        $summary = $message = $envelope->getMessage()::class;

        $schema = new ElicitationSchema(
            ['approve' => new BooleanSchemaDefinition(
                title: 'Retry failed message #'.$id,
                description: 'Re-executes the handler right now.',
            )],
            ['approve'],
        );

        $answer = $context->getClientGateway()->elicit(
            \sprintf(
                'Coroner wants to RETRY failed message #%s (%s). Retrying only makes sense once the underlying cause is fixed — otherwise the message usually lands right back in the graveyard. Approve?',
                $id,
                $summary,
            ),
            $schema,
        );

        if (!$answer->isAccepted() || true !== ($answer->content['approve'] ?? null)) {
            return $this->json([
                'status' => 'declined',
                'id' => (string) $id,
                'note' => 'The human operator did not approve. Nothing was touched.',
            ]);
        }

        $output = $this->runConsole(['messenger:failed:retry', $id, '--force']);

        return $this->json([
            'status' => 'retried',
            'id' => (string) $id,
            'command_output' => $output,
            'note' => 'Re-queued. If the underlying cause is still present it will fail again and reappear in the failure transport.',
        ]);
    }

    #[McpTool(
        name: 'discard_failed_message',
        title: 'Discard a failed message (asks the human)',
        description: 'Permanently removes one failed Messenger message from the failure transport. DESTRUCTIVE and irreversible: always asks the human operator for approval via MCP elicitation, and requires them to type the message id as a typed confirmation.',
    )]
    public function discardFailedMessage(string $id, RequestContext $context): string
    {
        $envelope = $this->envelope($id);
        $summary = $envelope->getMessage()::class;

        $schema = new ElicitationSchema(
            ['confirm_id' => new StringSchemaDefinition(
                title: 'Confirm discard of message #'.$id,
                description: \sprintf('Type the message id (%s) to confirm permanent removal.', $id),
                minLength: 1,
            )],
            ['confirm_id'],
        );

        $answer = $context->getClientGateway()->elicit(
            \sprintf(
                'Coroner wants to PERMANENTLY DISCARD failed message #%s (%s). This cannot be undone. For poison messages (invalid data) this is the right call; for anything else, fixing and retrying is usually better. Type the message id to confirm.',
                $id,
                $summary,
            ),
            $schema,
        );

        $typed = (string) ($answer->content['confirm_id'] ?? '');
        if (!$answer->isAccepted() || $typed !== $id) {
            return $this->json([
                'status' => 'declined',
                'id' => (string) $id,
                'note' => $answer->isAccepted()
                    ? 'The typed confirmation did not match the message id. Nothing was touched.'
                    : 'The human operator did not approve. Nothing was touched.',
            ]);
        }

        $output = $this->runConsole(['messenger:failed:remove', $id, '--force']);

        return $this->json([
            'status' => 'discarded',
            'id' => (string) $id,
            'command_output' => $output,
        ]);
    }

    private function failureReceiver(): ListableReceiverInterface
    {
        $receiver = $this->receiverLocator->get(self::FAILURE_TRANSPORT);
        if (!$receiver instanceof ListableReceiverInterface) {
            throw new \RuntimeException(\sprintf('The "%s" failure transport does not support listing messages.', self::FAILURE_TRANSPORT));
        }

        return $receiver;
    }

    private function envelope(string $id): Envelope
    {
        $envelope = $this->failureReceiver()->find($id);
        if (null === $envelope) {
            throw new \InvalidArgumentException(\sprintf('No failed message with id "%s". Call list_failed_messages first.', $id));
        }

        return $envelope;
    }

    private function firstErrorLine(Envelope $envelope): string
    {
        $error = $envelope->last(ErrorDetailsStamp::class);
        $message = $error?->getExceptionMessage() ?? 'unknown error';

        return strtok($message, "\n") ?: $message;
    }

    /**
     * @return list<array{function: string, at: string}>
     */
    private function stackTrace(?ErrorDetailsStamp $error): array
    {
        $flatten = $error?->getFlattenException();
        if (null === $flatten) {
            return [];
        }

        $frames = [];
        foreach (\array_slice($flatten->getTrace(), -12) as $frame) {
            $frames[] = [
                'function' => \sprintf('%s%s%s()',
                    $frame['class'] ?? '',
                    isset($frame['class']) ? '::' : '',
                    $frame['function'] ?? '?',
                ),
                'at' => \sprintf('%s:%s', $frame['file'] ?? '?', $frame['line'] ?? '?'),
            ];
        }

        return $frames;
    }

    private function handlerSource(string $messageClass): ?string
    {
        $handlerClass = 'App\Coroner\MessageHandler\\'.substr($messageClass, (int) strrpos($messageClass, '\\') + 1).'Handler';
        if (!class_exists($handlerClass)) {
            return null;
        }

        try {
            $file = (new \ReflectionClass($handlerClass))->getFileName();
        } catch (\ReflectionException) {
            return null;
        }

        return null === $file ? null : file_get_contents($file);
    }

    private function runConsole(array $args, int $timeout = 120): string
    {
        $process = new Process([PHP_BINARY, $this->projectDir.'/bin/console', ...$args], $this->projectDir);
        $process->setTimeout($timeout);
        $process->run();

        return trim($process->getOutput()."\n".$process->getErrorOutput());
    }

    private function json(mixed $data): string
    {
        try {
            return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }
}
