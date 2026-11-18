<?php

namespace App\Coroner\Mcp;

use Mcp\Client\Handler\Request\ElicitationCallbackInterface;
use Mcp\Schema\Elicitation\AbstractSchemaDefinition;
use Mcp\Schema\Elicitation\BooleanSchemaDefinition;
use Mcp\Schema\Elicitation\StringSchemaDefinition;
use Mcp\Schema\Enum\ElicitAction;
use Mcp\Schema\Enum\ElicitationMode;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Result\ElicitResult;

/**
 * The human side of the coroner-chat client: when the MCP server elicits
 * (retry / discard approval), this handler renders the request in the
 * terminal and turns the operator's keystrokes into an ElicitResult.
 *
 * Declining is always one keystroke away — and EOF (Ctrl-D) cancels.
 */
final class TerminalElicitation implements ElicitationCallbackInterface
{
    public function __invoke(ElicitRequest $request): ElicitResult
    {
        if (ElicitationMode::Url === $request->mode) {
            fwrite(STDERR, \PHP_EOL."\nCoroner needs your browser: {$request->url}\nPress Enter once done, anything else to decline: ");
            $line = $this->readLine();

            return '' === trim((string) $line)
                ? new ElicitResult(ElicitAction::Accept)
                : new ElicitResult(ElicitAction::Decline);
        }

        fwrite(STDERR, \PHP_EOL."\n=============== CORONER APPROVAL REQUIRED ===============\n");
        fwrite(STDERR, $request->message."\n");

        $content = [];
        foreach ($request->requestedSchema?->properties ?? [] as $name => $definition) {
            $answer = $this->ask($definition);
            if (null === $answer) {
                fwrite(STDERR, "Cancelled.\n");
                return new ElicitResult(ElicitAction::Cancel);
            }
            if ([] === $answer) {
                return new ElicitResult(ElicitAction::Decline);
            }
            $content[$name] = $answer['value'];
        }

        fwrite(STDERR, "---------------------------------------------------------\n");

        return new ElicitResult(ElicitAction::Accept, $content);
    }

    /**
     * @return array{value: mixed}|[]|null null = cancelled, [] = declined, otherwise the answer
     */
    private function ask(AbstractSchemaDefinition $definition): ?array
    {
        if ($definition instanceof BooleanSchemaDefinition) {
            fwrite(STDERR, \sprintf('%s%s [y/n]: ', self::label($definition), null !== $definition->default ? \sprintf(' (default: %s)', $definition->default ? 'y' : 'n') : ''));
            $line = strtolower(trim((string) $this->readLine()));
            if ('' === $line && null !== $definition->default) {
                return ['value' => $definition->default];
            }
            if ('y' === $line || 'yes' === $line) {
                return ['value' => true];
            }
            if ('n' === $line || 'no' === $line) {
                return [];
            }

            return null; // anything else cancels — never guess on destructive ops
        }

        if ($definition instanceof StringSchemaDefinition) {
            fwrite(STDERR, \sprintf('%s: ', self::label($definition)));
            $line = trim((string) $this->readLine());

            return ['value' => $line];
        }

        return null;
    }

    private static function label(AbstractSchemaDefinition $definition): string
    {
        return $definition->title ?? $definition->description ?? 'Please answer';
    }

    private function readLine(): string|false
    {
        $line = fgets(STDIN);

        return false === $line ? '' : $line;
    }
}
