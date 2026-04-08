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
