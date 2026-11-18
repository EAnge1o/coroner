<?php

namespace App\Coroner\Mcp;

use Mcp\Capability\Attribute\McpPrompt;
use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\Content\PromptMessage;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\Role;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Contracts\Service\ServiceProviderInterface;

/**
 * The read-only briefing room: MCP resources and prompts that any client can
 * pull. Tools do the work; these teach the client's model *how* to work —
 * the canonical coroner workflow, one click away in Claude Code.
 */
final class CoronerBriefings
{
    private const FAILURE_TRANSPORT = 'failed';

    public function __construct(
        #[Autowire(service: 'messenger.receiver_locator')]
        private readonly ServiceProviderInterface $receiverLocator,
    ) {
    }

    #[McpResource(
        uri: 'coroner://graveyard',
        name: 'graveyard-snapshot',
        title: 'Graveyard snapshot',
        description: 'A live markdown snapshot of the Symfony Messenger failure transport: every failed message with id, payload digest, error and retry count.',
        mimeType: 'text/markdown',
    )]
    public function graveyardSnapshot(): string
    {
        $lines = ["# The graveyard\n"];
        $rows = [];
        foreach ($this->failureReceiver()->all(50) as $envelope) {
            $rows[] = [
                (string) $envelope->last(TransportMessageIdStamp::class)?->getId(),
                $envelope->getMessage()::class,
                $this->digest($envelope->getMessage()),
                strtok($envelope->last(ErrorDetailsStamp::class)?->getExceptionMessage() ?? 'unknown', "\n"),
                \count($envelope->all(\Symfony\Component\Messenger\Stamp\RedeliveryStamp::class)),
            ];
        }

        if ([] === $rows) {
            return "# The graveyard\n\nEmpty. Nobody died today.\n";
        }

        $lines[] = "| id | class | payload | error | retries |";
        $lines[] = "|---|---|---|---|---|";
        foreach ($rows as $row) {
            $lines[] = '| '.implode(' | ', array_map(static fn (string $cell) => str_replace('|', '\\|', $cell), $row)).' |';
        }
        $lines[] = "\nUse `inspect_failed_message` on an id for the full autopsy, `analyze_failed_message` for a diagnosis.";

        return implode("\n", $lines);
    }

    #[McpPrompt(
        name: 'coroner_triage',
        title: 'Coroner triage',
        description: 'The canonical coroner workflow: list, autopsy, classify, propose — and never touch anything without the human.',
    )]
    public function triagePrompt(
        #[Schema(description: 'Optional focus, e.g. "only orders for curie@radium.dev" or "everything"')]
        string $focus = 'everything',
    ): array {
        return [
            new PromptMessage(Role::User, new TextContent(<<<'PROMPT'
                You are Coroner's assistant. Investigate the Symfony Messenger failure
                transport using the coroner tools, in this exact order:

                1. list_failed_messages — get an overview.
                2. For every message: inspect_failed_message, then analyze_failed_message.
                3. Present one verdict table: id | cause of death (transient/bug/poison) | recommended action.
                4. Group the verdicts: which messages can be retried right now (transient),
                   which need a code fix first (bug, propose the concrete patch), and which
                   should be discarded (poison).
                5. Ask the human which group to act on. For retries and discards, the server
                   will elicit an explicit human approval — announce that before you call.

                Never retry or discard anything without presenting your reasoning first.
                PROMPT."\n\nFocus: {$focus}\n"),
            ),
        ];
    }

    private function failureReceiver(): ListableReceiverInterface
    {
        $receiver = $this->receiverLocator->get(self::FAILURE_TRANSPORT);
        if (!$receiver instanceof ListableReceiverInterface) {
            throw new \RuntimeException(\sprintf('The "%s" failure transport does not support listing messages.', self::FAILURE_TRANSPORT));
        }

        return $receiver;
    }

    private function digest(object $message): string
    {
        try {
            $payload = json_decode(json_encode($message, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return '(unserializable payload)';
        }

        return implode(', ', array_map(
            static fn (string $key, mixed $value) => "{$key}: ".(is_scalar($value) ? var_export($value, true) : get_debug_type($value)),
            array_keys($payload),
            $payload,
        ));
    }
}
