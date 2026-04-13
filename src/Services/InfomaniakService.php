<?php

namespace BoldWeb\StatamicAiAssistant\Services;

use BoldWeb\StatamicAiAssistant\Support\OpenAiChatCompletionStream;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class InfomaniakService extends AbstractAiService
{
    /**
     * Call the Infomaniak API with the provided messages.
     *
     * @param array $messages
     * @return string
     */
    protected function callApi(array $messages): string
    {
        $payload = [
            'messages'    => $messages,
            'model'       => config('statamic-ai-assistant.infomaniak_model'),
            'temperature' => config('statamic-ai-assistant.temperature'),
            'max_tokens'  => config('statamic-ai-assistant.max_tokens'),
            'stream'      => false,
        ];

        try {
            $productId = config('statamic-ai-assistant.infomaniak_product_id');
            $apiToken  = config('statamic-ai-assistant.infomaniak_api_token');
            $url = "https://api.infomaniak.com/1/ai/{$productId}/openai/chat/completions";

            $timeout = (int) config('statamic-ai-assistant.infomaniak_http_timeout', 120);

            $response = Http::timeout($timeout)
                ->withToken($apiToken)
                ->post($url, $payload);

            if (! $response->successful()) {
                Log::error('Infomaniak API HTTP error', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 2000),
                ]);

                $hint = $response->json('error.message')
                    ?? $response->json('message')
                    ?? mb_substr($response->body(), 0, 500);

                throw new \RuntimeException(
                    __('Infomaniak request failed (:status): :hint', [
                        'status' => $response->status(),
                        'hint' => $hint,
                    ])
                );
            }

            $data = $response->json();

            if (! is_array($data)) {
                throw new \RuntimeException(__('Unexpected response from Infomaniak.'));
            }

            $content = $this->extractCompletionMessageContent($data);

            if ($content === '') {
                Log::warning('Infomaniak returned empty assistant content', [
                    'response_excerpt' => mb_substr(json_encode($data), 0, 2000),
                ]);

                throw new \RuntimeException(
                    __('The AI returned no text. Check your Infomaniak product ID, model name, and prompt size.')
                );
            }

            return $content;
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Infomaniak API exception', ['message' => $e->getMessage()]);

            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function callApiStreaming(array $messages, callable $onDelta): string
    {
        $productId = config('statamic-ai-assistant.infomaniak_product_id');
        $apiToken = config('statamic-ai-assistant.infomaniak_api_token');
        $url = "https://api.infomaniak.com/1/ai/{$productId}/openai/chat/completions";

        $payload = [
            'messages' => $messages,
            'model' => config('statamic-ai-assistant.infomaniak_model'),
            'temperature' => config('statamic-ai-assistant.temperature'),
            'max_tokens' => config('statamic-ai-assistant.max_tokens'),
            'stream' => true,
        ];

        $timeout = (int) config('statamic-ai-assistant.infomaniak_http_timeout', 120);

        try {
            $response = Http::timeout($timeout)
                ->withToken($apiToken)
                ->withOptions([
                    'stream' => true,
                    'read_timeout' => $timeout,
                ])
                ->post($url, $payload);
        } catch (\Throwable $e) {
            Log::error('Infomaniak streaming API exception', ['message' => $e->getMessage()]);

            throw new \RuntimeException($e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            $hint = $response->json('error.message')
                ?? $response->json('message')
                ?? mb_substr($response->body(), 0, 500);

            throw new \RuntimeException(
                __('Infomaniak request failed (:status): :hint', [
                    'status' => $response->status(),
                    'hint' => $hint,
                ])
            );
        }

        $stream = $response->toPsrResponse()->getBody();
        $full = OpenAiChatCompletionStream::collect($stream, $onDelta);

        if ($full === '') {
            throw new \RuntimeException(
                __('The AI returned no text. Check your Infomaniak product ID, model name, and prompt size.')
            );
        }

        return $full;
    }
}
