<?php

namespace BoldWeb\StatamicAiAssistant\Services;

use Statamic\Facades\Site;

// To add a new service just extend this abstract and implement the callApi function.
abstract class AbstractAiService
{

    /**
     *
     * @param array $messages
     * @return string
     */
    abstract protected function callApi(array $messages): string;


    /**
     * Generate content using the provided prompt.
     *
     * @param string $prompt
     * @return string
     */
    public function generateContentFromPrompt(string $prompt): string
    {
        $currentLocale = optional(Site::selected())->locale() ?: 'en';
        $promptAdded = 'if not specified YOU NEED to write this article in this language: ' . $currentLocale;
        
        $messages = [];
        if (config('statamic-ai-assistant.prompt_preface')) {
            $messages[] = [
                'role'    => 'system',
                'content' => config('statamic-ai-assistant.prompt_preface') . $promptAdded,
            ];
        }
        
        $messages[] = [
            'role'    => 'user',
            'content' => $prompt,
        ];
        
        $content = $this->callApi($messages);
        return $this->cleanResult($content);
    }

    /**
     * Generate refactored content using the provided text and instructions.
     *
     * @param string $textToRefactor
     * @param string $prompt
     * @return string
     */
    public function generateRefactorFromPrompt(string $textToRefactor, string $prompt): string
    {
        $messages = [];
        if (config('statamic-ai-assistant.prompt_refactor_preface')) {
            $messages[] = [
                'role'    => 'system',
                'content' => config('statamic-ai-assistant.prompt_refactor_preface'),
            ];
        }
        
        $combinedPrompt = "Please refactor the following text:\n\n"
            . $textToRefactor
            . "\n\nUser instructions for the refactoring: " . $prompt;
        
        $messages[] = [
            'role'    => 'user',
            'content' => $combinedPrompt,
        ];
        
        $content = $this->callApi($messages);
        return $this->cleanResult($content);
    }

    /**
     * Generate HTML refactored content using the provided text and instructions.
     *
     * @param string $textToRefactor
     * @param string $prompt
     * @return string
     */
    public function generateHtmlRefactorFromPrompt(string $textToRefactor, string $prompt): string
    {
        $messages = [];
        if (config('statamic-ai-assistant.prompt_html_refactor_preface')) {
            $messages[] = [
                'role'    => 'system',
                'content' => config('statamic-ai-assistant.prompt_html_refactor_preface'),
            ];
        }
        
        $combinedPrompt = "Please refactor the following text:\n\n"
            . $textToRefactor
            . "\n\nUser instructions for the refactoring: " . $prompt;
        
        $messages[] = [
            'role'    => 'user',
            'content' => $combinedPrompt,
        ];
        
        $content = $this->callApi($messages);
        return $this->cleanResult($content);
    }

    /**
     * Translate markdown content to a target language.
     * Preserves front matter structure and only translates content values, not keys or sensitive data.
     *
     * @param string $markdownContent
     * @param string $targetLanguage
     * @param string|null $targetLanguageName
     * @return string
     */
    public function translateMarkdown(string $markdownContent, string $targetLanguage, ?string $targetLanguageName = null): string
    {
        $languageDescription = $targetLanguageName 
            ? "{$targetLanguageName} (ISO code: {$targetLanguage})" 
            : "language with ISO code: {$targetLanguage}";
            
        $prompt = "Translate the following markdown file to {$languageDescription}. 

IMPORTANT RULES:
1. ONLY translate the text content values, NOT the front matter keys (field names)
2. DO NOT translate or modify:
   - Front matter keys (everything before the colons in YAML)
   - URLs, links, file paths
   - Email addresses
   - Dates, timestamps, IDs, numbers
   - HTML tags and attributes
   - Code blocks
   - Any data that looks like technical identifiers
3. PRESERVE the exact markdown structure and formatting
4. KEEP HTML structure intact in HTML/Bard fields
5. Return ONLY the translated markdown file, nothing else
6. Make sure ALL text content is translated to the target language

Markdown content to translate:
---
{$markdownContent}
---";

        $messages = [];
        $messages[] = [
            'role' => 'user',
            'content' => $prompt,
        ];

        $content = $this->callApi($messages);
        return $this->cleanResult($content);
    }

    /**
     * Clean up the result by converting encoding and replacing erroneous characters.
     *
     * @param string $content
     * @return string
     */
    public function cleanResult($content = ''): string
    {
        $content = mb_convert_encoding($content, 'UTF-8');
        $content = trim($content, '"');
        $content = str_replace("'", "'", $content);
        $content = str_replace("â", "'", $content);
        $content = str_replace("  ", " ", $content);
        $content = str_replace("â", "&", $content);
        $content = str_replace("'", '-', $content);
        $content = str_replace("", '', $content);

        return $content;
    }
}
