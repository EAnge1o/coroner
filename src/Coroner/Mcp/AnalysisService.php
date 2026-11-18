<?php

namespace App\Coroner\Mcp;

use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The "pathology lab": one structured LLM call over the evidence pack of a
 * dead message. Runs on Ollama by default, so judges can clone and run
 * this without any API key.
 */
final class AnalysisService
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
        You are a forensic pathologist for Symfony Messenger messages. You are
        given the evidence pack of one message that died in the failure
        transport: its payload, the exception it raised, its stack trace, how
        many times it was retried, and the source of the handler that killed it.

        Classify the cause of death into exactly one class:
        - "transient": the failure came from the outside world (network, third-party
          API down, timeouts). The same code will succeed once the world recovers.
          Recommended action: "retry".
        - "bug": the application code itself is wrong (missing array keys, null
          handling, wrong assumptions). Retrying cannot help until the code is fixed.
          Recommended action: "fix_then_retry".
        - "poison": the message data itself is invalid (negative quantities, missing
          ids). It can NEVER succeed; retrying only wastes resources.
          Recommended action: "discard".
        If you genuinely cannot tell, use "unknown" and explain what evidence is missing.

        Respond with ONLY a JSON object, no markdown fences, exactly this shape:
        {"failure_class":"transient|bug|poison|unknown","root_cause":"one sentence","evidence":"the strongest 1-2 clues you relied on","recommended_action":"retry|fix_then_retry|discard","fix_suggestion":"concrete next step for a developer, empty string if not applicable","confidence":0.0}
        PROMPT;

    /**
     * JSON schema passed as the platform's `response_format` option. The Ollama
     * bridge maps it to Ollama's native `format` parameter, which *forces*
     * structurally valid JSON — no more markdown-fence soup from small models.
     * Hand-rolled parsing below stays as the safety net for other providers.
     */
    private const DIAGNOSIS_JSON_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'failure_class' => ['type' => 'string', 'enum' => ['transient', 'bug', 'poison', 'unknown']],
            'root_cause' => ['type' => 'string'],
            'evidence' => ['type' => 'string'],
            'recommended_action' => ['type' => 'string', 'enum' => ['retry', 'fix_then_retry', 'discard']],
            'fix_suggestion' => ['type' => 'string'],
            'confidence' => ['type' => 'number'],
        ],
        'required' => ['failure_class', 'root_cause', 'evidence', 'recommended_action', 'fix_suggestion', 'confidence'],
    ];

    public function __construct(
        #[Autowire(service: 'ai.agent.coroner_analyst')]
        private readonly AgentInterface $analyst,
    ) {
    }

    /**
     * @param array<string, mixed> $evidence the evidence pack built by CoronerTools
     *
     * @return array<string, mixed>
     */
    public function diagnose(array $evidence): array
    {
        $messages = new MessageBag(
            Message::forSystem(self::SYSTEM_PROMPT),
            Message::ofUser($this->json($evidence)),
        );

        $content = $this->analyst->call($messages, [
            'response_format' => ['json_schema' => ['schema' => self::DIAGNOSIS_JSON_SCHEMA]],
        ])->getContent();

        $diagnosis = $this->extractJson($content);

        return $diagnosis ?? [
            'failure_class' => 'unknown',
            'root_cause' => 'The analyst did not return valid JSON.',
            'evidence' => '',
            'recommended_action' => 'inspect_failed_message and decide manually.',
            'fix_suggestion' => '',
            'confidence' => 0.0,
            'raw_response' => $content,
        ];
    }

    private function extractJson(string $content): ?array
    {
        $content = trim($content);
        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if (false === $start || false === $end || $end <= $start) {
            return null;
        }

        try {
            $decoded = json_decode(substr($content, $start, $end - $start + 1), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($decoded) ? $decoded : null;
    }

    private function json(array $data): string
    {
        try {
            return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return '{"error": '.json_encode($e->getMessage()).'}';
        }
    }
}
