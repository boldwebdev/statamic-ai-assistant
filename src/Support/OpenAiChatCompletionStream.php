<?php

namespace BoldWeb\StatamicAiAssistant\Support;

use Psr\Http\Message\StreamInterface;

/**
 * Reads an OpenAI-compatible chat completion SSE stream and reassembles assistant text.
 */
final class OpenAiChatCompletionStream
{
    /**
     * @param  callable(string): void  $onDelta
     */
    public static function collect(StreamInterface $stream, callable $onDelta): string
    {
        $full = '';
        $buffer = '';

        while (! $stream->eof()) {
            $buffer .= $stream->read(8192);
            $full = self::consumeBufferBlocks($buffer, $full, $onDelta);
        }

        if ($buffer !== '') {
            $full = self::consumeBufferBlocks($buffer."\n\n", $full, $onDelta);
        }

        return $full;
    }

    private static function consumeBufferBlocks(string &$buffer, string $full, callable $onDelta): string
    {
        while (($pos = strpos($buffer, "\n\n")) !== false) {
            $block = substr($buffer, 0, $pos);
            $buffer = substr($buffer, $pos + 2);
            $full = self::consumeSseBlock($block, $full, $onDelta);
        }

        return $full;
    }

    private static function consumeSseBlock(string $block, string $full, callable $onDelta): string
    {
        foreach (explode("\n", $block) as $line) {
            $line = trim($line);

            if ($line === '' || ! str_starts_with($line, 'data:')) {
                continue;
            }

            $data = trim(substr($line, strlen('data:')));

            if ($data === '' || $data === '[DONE]') {
                continue;
            }

            $json = json_decode($data, true);

            if (! is_array($json)) {
                continue;
            }

            $delta = self::extractDeltaText($json);

            if ($delta !== null && $delta !== '') {
                $full .= $delta;
                $onDelta($delta);
            }
        }

        return $full;
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private static function extractDeltaText(array $json): ?string
    {
        $choice = $json['choices'][0] ?? null;

        if (! is_array($choice)) {
            return null;
        }

        $delta = $choice['delta'] ?? null;

        if (! is_array($delta)) {
            return null;
        }

        $parts = [];

        foreach (['reasoning_content', 'reasoning', 'content'] as $key) {
            if (! isset($delta[$key])) {
                continue;
            }

            $v = $delta[$key];

            if (is_string($v) && $v !== '') {
                $parts[] = $v;
            }
        }

        if ($parts === []) {
            return null;
        }

        return implode('', $parts);
    }
}
