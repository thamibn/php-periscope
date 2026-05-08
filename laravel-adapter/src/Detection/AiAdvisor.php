<?php

declare(strict_types=1);

namespace Periscope\Laravel\Detection;

use Periscope\Laravel\Bridge\ExtensionBridge;
use Throwable;

use function Laravel\Ai\agent;

/**
 * Opt-in AI advisor for slow queries, N+1 patterns, exceptions, and error logs.
 *
 * Delegates to Laravel's first-party AI SDK (`laravel/ai`), so we get OpenAI,
 * Anthropic, Gemini, Ollama, OpenRouter, DeepSeek, Groq, Mistral, xAI, Azure
 * and Bedrock for free — provider switching, retries, queueing, and streaming
 * all live in the SDK. This class is a thin orchestration layer that picks
 * the right system prompt per kind and rate-limits suggestions per request.
 *
 * Disabled by default. When enabled:
 *   - Hard cap (`max_suggestions_per_request`) shared across ALL kinds, so a
 *     burst of slow queries can't starve exception suggestions.
 *   - Errors are swallowed; advisories are best-effort.
 *
 * Emits `ai_suggestion` events (kind = `slow_query` | `n_plus_one` |
 * `exception` | `error_log`) so the UI / `/api/insights` can render them
 * inline next to the originating event.
 *
 * To use: `composer require laravel/ai` and configure your provider per
 * the SDK docs (https://laravel.com/docs/13.x/ai-sdk).
 */
final class AiAdvisor
{
    private int $emittedThisRequest = 0;

    public function __construct(
        private readonly ExtensionBridge $bridge,
        private readonly bool $enabled,
        private readonly int $maxPerRequest,
    ) {}

    public function isEnabled(): bool
    {
        return $this->enabled && function_exists('Laravel\\Ai\\agent');
    }

    /**
     * @param 'slow_query'|'n_plus_one'|'exception'|'error_log' $kind
     * @param array<string, mixed>|null $callSite
     */
    public function advise(string $kind, string $body, ?array $callSite, string $title = ''): void
    {
        if (!$this->isEnabled() || $this->emittedThisRequest >= $this->maxPerRequest) {
            return;
        }

        $this->emittedThisRequest++;

        $suggestion = $this->ask($kind, $body, $title);
        if ($suggestion === null) {
            return;
        }

        $this->bridge->recordEvent('ai_suggestion', [
            'kind'       => $kind,
            'title'      => $title,
            'suggestion' => $suggestion,
        ], $callSite);
    }

    private function ask(string $kind, string $body, string $title): ?string
    {
        try {
            $response = agent(instructions: $this->systemPrompt($kind))
                ->prompt($title === '' ? $body : "{$title}\n\n{$body}");

            return trim((string) $response) ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    private function systemPrompt(string $kind): string
    {
        return match ($kind) {
            'slow_query' => 'You are a senior Laravel + MySQL performance engineer. '
                . 'Given a single slow SQL query, respond with at most three terse, concrete '
                . 'recommendations: which index to add (give a CREATE INDEX statement), how to '
                . 'rewrite the Eloquent / Builder call (with code), and what to avoid. '
                . 'No prose, no greetings, no markdown headings — just the recommendations.',

            'n_plus_one' => 'You are a senior Laravel performance engineer. '
                . 'Given a SQL pattern that fired N times in one request, respond with: '
                . '(1) the eager-load that fixes it (`->with(...)`, `->load(...)` or whereIn), '
                . '(2) which model relation needs to be defined or renamed, '
                . '(3) any related N+1s likely lurking. '
                . 'Output runnable PHP, no greetings.',

            'exception' => 'You are a senior Laravel + PHP debugger. '
                . 'Given an exception class, message, and stack trace, respond with: '
                . '(1) the most likely root cause in one sentence, '
                . '(2) the exact file:line a fix should land in, '
                . '(3) a minimal code patch. No prose, no apologies.',

            'error_log' => 'You are a senior Laravel + PHP operator. '
                . 'Given a single error-level log line and its context, respond with: '
                . '(1) what is most likely failing, '
                . '(2) a defensive code change (with file:line guess) that prevents recurrence, '
                . '(3) an alert/metric worth adding. Terse, concrete, no prose.',

            default => 'You are a senior Laravel debugger. Be concrete and terse.',
        };
    }
}
