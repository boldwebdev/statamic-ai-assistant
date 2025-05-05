<?php

namespace BoldWeb\StatamicAiAssistant\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

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

            $response = Http::withToken($apiToken)->post($url, $payload);

            if ($response->successful()) {
                $data = $response->json();
                $content = $data['choices'][0]['message']['content'] ?? '';
            } else {
                Log::error('Infomaniak API Error: ' . $response->body());
                $content = '';
            }
        } catch (\Exception $e) {
            Log::error('Infomaniak API Exception: ' . $e->getMessage());
            $content = '';
        }

        return $content;
    }
}
