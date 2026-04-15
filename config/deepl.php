<?php

return [

    /*
    |--------------------------------------------------------------------------
    | DeepL API Key
    |--------------------------------------------------------------------------
    |
    | Your DeepL API authentication key. Get one at https://www.deepl.com/pro-api
    | Set DEEPL_API_KEY in your .env file.
    |
    */

    'api_key' => env('DEEPL_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | English / Portuguese target variants (DeepL API)
    |--------------------------------------------------------------------------
    |
    | Target language "en" and "pt" are not accepted; the API requires a variant.
    | Used when the mapped target is still the ambiguous code (e.g. site locale "en").
    |
    */

    'english_target' => env('DEEPL_ENGLISH_TARGET', 'en-GB'),

    'portuguese_target' => env('DEEPL_PORTUGUESE_TARGET', 'pt-PT'),

    /*
    |--------------------------------------------------------------------------
    | Language Mapping
    |--------------------------------------------------------------------------
    |
    | Maps your Statamic site handles and locale strings to DeepL language codes.
    | DeepL codes: https://developers.deepl.com/docs/resources/supported-languages
    |
    | Use en-GB / en-US (not bare "en") for English *targets* where applicable.
    | Source languages are normalized automatically (en-GB → en, pt-PT → pt, etc.).
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Field handles to translate even when localizable = false
    |--------------------------------------------------------------------------
    |
    | Statamic blueprints sometimes mark hero titles as non-localizable (shared),
    | but localized entries still store their own copy — and DeepL must translate
    | it. Add handles here so bulk / entry translation never skips them.
    |
    */

    'force_translate_handles' => [
        'hero_title',
    ],

    'language_mapping' => [
        // ISO / Statamic locale primary subtags (regional codes like de-CH are normalized to these)
        'de' => 'de',
        'en' => 'en-GB',
        'en-gb' => 'en-GB',
        'en-us' => 'en-US',
        'fr' => 'fr',
        'it' => 'it',
        'pt' => 'pt-PT',
        'pt-br' => 'pt-BR',
        'pt-pt' => 'pt-PT',
        // Optional explicit regional overrides if you need a DeepL variant (e.g. pt-BR)
        // 'de-ch' => 'de',
        // 'de_ch' => 'de',
    ],

];
