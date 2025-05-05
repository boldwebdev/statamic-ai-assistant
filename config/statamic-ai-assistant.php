<?php

return [

    /*
    |--------------------------------------------------------------------------
    | PROVIDER Key
    |--------------------------------------------------------------------------
    |
    | This plugin supports the following providers for now:
    | - infomaniak (CH, respect the data of your clients)
    | - groq (US, speed)
    |
    | Set the provider in your .env file using:
    | PROVIDER_NAME=infomaniak
    | or
    | PROVIDER_NAME=groq
    |
    */

    'provider_name' => env('STATAMIC_AI_ASSISTANT_PROVIDER_NAME', 'infomaniak'),

    /*
    |--------------------------------------------------------------------------
    | Translation functionality 
    |--------------------------------------------------------------------------
    |
    | This allows you to disable the translations functionality.
    | disable it with your .env file using:
    | STATAMIC_AI_ASSISTANT_TRANSLATIONS=false
    |
    */

    'translations' => env('STATAMIC_AI_ASSISTANT_TRANSLATIONS', true),

    /*
    |--------------------------------------------------------------------------
    | INFOMANIAK product id
    |--------------------------------------------------------------------------
    |
    | Mandatory: If you use infomaniak, you need to provide your product ID to use the API!
    | Get your id: https://developer.infomaniak.com/docs/api/get/1/ai
    | Tips: Find your id in your infomaniak dashboard (computing->AI tools)
    |
    */
    'infomaniak_product_id' => env('INFOMANIAK_PRODUCT_ID'),

    /*
    |--------------------------------------------------------------------------
    | API Keys
    |--------------------------------------------------------------------------
    |
    | Use the appropriate API key for your chosen provider.
    |
    | For GROQ: set GROQ_API_KEY in your environment.
    | For INFOMANIAK: set INFOMANIAK_API_TOKEN in your environment.
    |
    */

    'groq_api_key' => env('GROQ_API_KEY'),
    'infomaniak_api_token' => env('INFOMANIAK_API_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Model / Product Identifiers
    |--------------------------------------------------------------------------
    |
    | For GROQ, use GROQ_MODEL.
    | For INFOMANIAK, use INFOMANIAK_PRODUCT_ID and INFOMANIAK_MODEL.
    |
    */
    'groq_model' => env('GROQ_MODEL', 'llama-3.1-8b-instant'),
    'infomaniak_model' => env('INFOMANIAK_MODEL', 'mistral24b'),

    /*
    |--------------------------------------------------------------------------
    | Prompt Preface
    |--------------------------------------------------------------------------
    |
    | You can specify a statement that is sent as context to the API before generating a response.
    | Set STATAMIC_AI_ASSISTANT_PREFACE in your .env file to override the text for the initial prompts
    | Set STATAMIC_AI_ASSISTANT_REFACTOR_PREFACE in your .env file to override the text for the text refactoring
    |
    */

    'prompt_preface' => env(
        'STATAMIC_AI_ASSISTANT_PREFACE',
        'You are a seasoned SEO expert who writes clear, professional, seo friendly and engaging articles in plain text.
         Your task is to produce fully readable articles for an end user.
         Do not include any markdown formatting, HTML tags, header tags, meta descriptions, or any other markup.
         Write only the final article text in plain text, ensuring it is clear, direct, and free of extraneous formatting elements.'
    ),

    'prompt_refactor_preface' => env(
        'STATAMIC_AI_ASSISTANT_REFACTOR_PREFACE',
        'You are a professional text refactoring expert. Your only task is to transform the provided text according to the specific instructions given by the user. 
        Output solely the final refactored text in plain text, DO NOT INCLUDE the user instructions, any commentary, or any additional text. 
        Preserve the original language and maintain a similar length to the input text unless instructed otherwise.'
    ),

    'prompt_html_refactor_preface' => env(
        'STATAMIC_AI_ASSISTANT_HTML_REFACTOR_PREFACE',
        'You are a professional text refactoring expert. 
        Your only task is to transform the provided HTML text according to the specific instructions given by the user. 
        Output solely the final refactored HTML! DO NOT INCLUDE the user instructions, any commentary, or any additional text. 
        ALWAYS RENDER the output as valid HTML. Keep all links intact unless the user specifically instructs you to modify them, 
        and always strive to preserve the original HTML structure unless instructed otherwise.
        ALWAYS update lorem ipsum. Preserve the original language and maintain a similar length to the input text unless otherwise specified.'
    ),

    /*
    |--------------------------------------------------------------------------
    | Generation Parameters
    |--------------------------------------------------------------------------
    |
    | Define generation parameters
    |
    |
    */

    'temperature' => env('STATAMIC_AI_ASSISTANT_TEMPERATURE', 0.5),
    'max_tokens' => env('STATAMIC_AI_ASSISTANT_MAX_TOKENS', 1000),

];
