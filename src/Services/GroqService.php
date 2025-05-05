<?php

namespace BoldWeb\StatamicAiAssistant\Services;

use Illuminate\Support\Facades\Log;
use LucianoTonet\GroqLaravel\Facades\Groq;

class GroqService extends AbstractAiService
{
    /**
     * Call the Groq API with the provided messages.
     *
     * @param array $messages
     * @return string
     */
    protected function callApi(array $messages): string
    {
        $payload = [
            'messages'   => $messages,
            'model'      => config('statamic-ai-assistant.groq_model'),
            'temperature'=> config('statamic-ai-assistant.temperature'),
            'max_tokens' => config('statamic-ai-assistant.max_tokens'),
            'stop'       => null,
            'stream'     => false,
        ];

        try {
            $response = Groq::chat()->completions()->create($payload);
            $content = $response['choices'][0]['message']['content'] ?? '';
        } catch (\Exception $e) {
            Log::error('Groq API Error: ' . $e->getMessage());
            $content = '';
        }

        return $content;
    }
}
