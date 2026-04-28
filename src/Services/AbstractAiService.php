<?php

namespace BoldWeb\StatamicAiAssistant\Services;

use BoldWeb\StatamicAiAssistant\Support\TrimAiOutput;
use Statamic\Facades\Site;

abstract class AbstractAiService
{
    /**
     * @param  array  $messages
     * @return string
     */
    abstract protected function callApi(array $messages): string;

    /**
     * Send raw messages to the LLM and return the raw response.
     *
     * Temporarily overrides max_tokens if $maxTokens is provided.
     *
     * @param  array<array{role: string, content: string}>  $messages
     * @param  callable(string): void|null  $onToken  Invoked with each streamed text delta (OpenAI-compatible providers only)
     */
    public function generateFromMessages(array $messages, ?int $maxTokens = null, ?callable $onToken = null): string
    {
        $originalMaxTokens = null;

        if ($maxTokens !== null) {
            $originalMaxTokens = config('statamic-ai-assistant.max_tokens');
            config(['statamic-ai-assistant.max_tokens' => $maxTokens]);
        }

        try {
            if ($onToken !== null) {
                return $this->callApiStreaming($messages, $onToken);
            }

            return $this->callApi($messages);
        } finally {
            if ($originalMaxTokens !== null) {
                config(['statamic-ai-assistant.max_tokens' => $originalMaxTokens]);
            }
        }
    }

    /**
     * Stream chat completion tokens to $onDelta and return the full assistant text.
     *
     * @param  array<array{role: string, content: string}>  $messages
     * @param  callable(string): void  $onDelta
     */
    protected function callApiStreaming(array $messages, callable $onDelta): string
    {
        throw new \RuntimeException(__('Streaming is not supported for this AI provider.'));
    }

    /**
     * Whether this provider supports OpenAI-style chat tools (function calling).
     */
    public function supportsChatTools(): bool
    {
        return false;
    }

    /**
     * Non-streaming chat completion with optional tools. Returns the raw API JSON (OpenAI shape).
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $tools
     * @param  string|array<string, mixed>  $toolChoice  'auto'|'required'|'none' or {type: 'function', function: {name: '...'}}
     * @return array<string, mixed>
     */
    /**
     * @param  callable(): void|null  $streamHeartbeat  Throttled CP keepalive while the HTTP client receives the completion body.
     */
    public function createChatCompletion(array $messages, ?int $maxTokens, array $tools = [], string|array $toolChoice = 'auto', ?callable $streamHeartbeat = null): array
    {
        throw new \RuntimeException(__('This AI provider does not support tool calling for entry generation.'));
    }

    /**
     * Generate content using the provided prompt.
     */
    public function generateContentFromPrompt(string $prompt): string
    {
        $currentLocale = optional(Site::selected())->locale() ?: 'en';
        $promptAdded = 'if not specified YOU NEED to write this article in this language: '.$currentLocale;

        $messages = [];
        if (config('statamic-ai-assistant.prompt_preface')) {
            $messages[] = [
                'role' => 'system',
                'content' => config('statamic-ai-assistant.prompt_preface').$promptAdded,
            ];
        }

        $messages[] = [
            'role' => 'user',
            'content' => $prompt,
        ];

        $content = $this->callApi($messages);

        return $this->cleanResult($content);
    }

    /**
     * Generate refactored content using the provided text and instructions.
     */
    public function generateRefactorFromPrompt(string $textToRefactor, string $prompt): string
    {
        $messages = [];
        if (config('statamic-ai-assistant.prompt_refactor_preface')) {
            $messages[] = [
                'role' => 'system',
                'content' => config('statamic-ai-assistant.prompt_refactor_preface'),
            ];
        }

        $combinedPrompt = "Please refactor the following text:\n\n"
            .$textToRefactor
            ."\n\nUser instructions for the refactoring: ".$prompt;

        $messages[] = [
            'role' => 'user',
            'content' => $combinedPrompt,
        ];

        $content = $this->callApi($messages);

        return $this->cleanResult($content);
    }

    /**
     * Generate HTML refactored content using the provided text and instructions.
     */
    public function generateHtmlRefactorFromPrompt(string $textToRefactor, string $prompt): string
    {
        $messages = [];
        if (config('statamic-ai-assistant.prompt_html_refactor_preface')) {
            $messages[] = [
                'role' => 'system',
                'content' => config('statamic-ai-assistant.prompt_html_refactor_preface'),
            ];
        }

        $combinedPrompt = "Please refactor the following text:\n\n"
            .$textToRefactor
            ."\n\nUser instructions for the refactoring: ".$prompt;

        $messages[] = [
            'role' => 'user',
            'content' => $combinedPrompt,
        ];

        $content = $this->callApi($messages);

        return $this->cleanResult($content);
    }

    /**
     * Extract assistant text from an OpenAI-style chat completion JSON payload.
     *
     * @param  array<string, mixed>  $data
     */
    protected function extractCompletionMessageContent(array $data): string
    {
        $choice = $data['choices'][0] ?? null;
        if (! is_array($choice)) {
            return '';
        }

        $message = $choice['message'] ?? null;
        if (! is_array($message)) {
            return '';
        }

        $content = $message['content'] ?? null;

        if (is_string($content)) {
            return $content;
        }

        if (is_array($content)) {
            $parts = [];
            foreach ($content as $part) {
                if (is_array($part) && ($part['type'] ?? '') === 'text' && isset($part['text'])) {
                    $parts[] = (string) $part['text'];
                }
            }

            return implode("\n", $parts);
        }

        return '';
    }

    /**
     * Clean up the result by converting encoding and replacing erroneous characters.
     */
    public function cleanResult($content = ''): string
    {
        $content = mb_convert_encoding($content, 'UTF-8');
        $content = trim($content, '"');
        $content = str_replace("\u{2019}", "'", $content);
        $content = str_replace("\u{00E2}", "'", $content);
        $content = str_replace('  ', ' ', $content);
        $content = str_replace("\u{00E2}", '&', $content);
        $content = str_replace("\u{2018}", '-', $content);
        $content = str_replace("\u{0080}", '', $content);

        return TrimAiOutput::normalize($content);
    }
}
