<?php

namespace App\Coroner\Command;

use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The zero-setup MCP client: an Ollama-driven agent whose toolbox is the
 * coroner MCP server itself. Demonstrates the full loop — tools, diagnosis
 * and human-in-the-loop elicitation — without any external API key.
 */
#[AsCommand('coroner:chat', 'Chat with the Coroner agent about your failed Messenger messages')]
final readonly class CoronerChatCommand
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
        You are Coroner's assistant, investigating Symfony Messenger failed messages.
        Start by listing the failed messages. Inspect or analyze anything that looks
        interesting, then propose the fix for each message: retry (once the cause is
        fixed), fix_then_retry, or discard. When you call retry_failed_message or
        discard_failed_message the server will ask the human for approval via MCP
        elicitation — tell them what to expect, and report the outcome afterwards.
        Finish with a short verdict table: message id, cause of death, action.
        PROMPT;

    public function __construct(
        #[Autowire(service: 'ai.agent.coroner_chat')]
        private AgentInterface $agent,
    ) {
    }

    public function __invoke(): int
    {
        fwrite(STDERR, "Coroner chat — ask about your failed messages ('exit' to quit).\n");
        fwrite(STDERR, "Needs a local Ollama (OLLAMA_ENDPOINT) and the demo seeded via coroner:demo:break.\n");

        while (true) {
            fwrite(STDERR, "\nyou> ");
            $line = fgets(STDIN);
            if (false === $line) {
                break;
            }
            $line = trim($line);
            if ('' === $line) {
                continue;
            }
            if (\in_array(strtolower($line), ['exit', 'quit'], true)) {
                break;
            }

            try {
                $answer = $this->agent->call(new MessageBag(
                    Message::forSystem(self::SYSTEM_PROMPT),
                    Message::ofUser($line),
                ))->getContent();
            } catch (\Throwable $e) {
                fwrite(STDERR, \sprintf("error: %s\n", $e->getMessage()));
                continue;
            }

            fwrite(STDOUT, $answer."\n");
        }

        return Command::SUCCESS;
    }
}
