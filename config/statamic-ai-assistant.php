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
    | Infomaniak HTTP client
    |--------------------------------------------------------------------------
    |
    | Laravel's HTTP client defaults to a 30 second timeout. The entry generator
    | sends large system prompts (full blueprint schema) and may use up to
    | generator_max_tokens — Infomaniak often needs longer than 30s to respond.
    | Keep this in line with the CP axios timeout for /cp/ai-generate/generate (120s).
    |
    */

    'infomaniak_http_timeout' => max(30, (int) env('STATAMIC_AI_ASSISTANT_INFOMANIAK_HTTP_TIMEOUT', 120)),

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

    /*
    |--------------------------------------------------------------------------
    | Translation Settings
    |--------------------------------------------------------------------------
    |
    | Configure how the bulk translation system works.
    |
    | translation_mode: 'sync' (always synchronous), 'async' (always use queue),
    |                   'auto' (sync for small batches, async for larger)
    | translation_sync_threshold: when mode is 'auto', batches above this count use async
    | translation_queue: the queue name for async translation jobs
    |
    */

    'translation_mode' => env('STATAMIC_AI_ASSISTANT_TRANSLATION_MODE', 'auto'),
    'translation_sync_threshold' => 3,
    'translation_queue' => env('STATAMIC_AI_ASSISTANT_TRANSLATION_QUEUE', 'translations'),

    /*
    |--------------------------------------------------------------------------
    | Linked entries (DeepL translation graph depth)
    |--------------------------------------------------------------------------
    |
    | When translating an entry, linked entries (entries/link fields, Bard entry
    | links, nested replicator data) can be translated recursively so IDs point
    | to localized siblings. This value is the maximum depth of that recursion
    | (0 = only remap IDs if a translation already exists; 1 = one hop, default).
    | Admins override via .env — the Control Panel does not expose this control.
    |
    */

    'linked_entries_max_depth' => max(0, min(5, (int) env('STATAMIC_AI_ASSISTANT_LINKED_ENTRIES_MAX_DEPTH', 1))),

    /*
    |--------------------------------------------------------------------------
    | BOLD agent settings (set hints)
    |--------------------------------------------------------------------------
    |
    | YAML file path for per-block AI hints (replicator / components sets).
    | Default is under content/ so it can be versioned in git. Set
    | STATAMIC_AI_ASSISTANT_SET_HINTS_PATH to an absolute path, or a path
    | relative to the project root, to override.
    |
    | Legacy location (pre–configurable path): storage/app/statamic-ai-assistant/set-hints.yaml
    | — automatically migrated once when the new file does not exist yet.
    |
    */

    'set_hints_path' => env('STATAMIC_AI_ASSISTANT_SET_HINTS_PATH', base_path('content/statamic-ai-assistant/set-hints.yaml')),

    /*
    |--------------------------------------------------------------------------
    | Figma OAuth (entry generator design context)
    |--------------------------------------------------------------------------
    |
    | Create an app at https://www.figma.com/developers/apps and register the
    | redirect URL shown in BOLD agent settings. Store credentials only in .env
    | (never commit). Run `php artisan config:clear` after changing .env.
    |
    | Legacy: credentials were stored in storage/app/statamic-ai-assistant/figma-app.yaml
    | — delete that file after migrating to these variables.
    |
    */

    'figma_oauth_client_id' => env('STATAMIC_AI_ASSISTANT_FIGMA_OAUTH_CLIENT_ID', ''),
    'figma_oauth_client_secret' => env('STATAMIC_AI_ASSISTANT_FIGMA_OAUTH_CLIENT_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | AI Entry Generator
    |--------------------------------------------------------------------------
    |
    | Enable or disable the AI entry generator feature.
    | The generator creates new collection entries from a natural language prompt.
    |
    | generator_max_tokens: Higher than the default max_tokens because the
    | generator produces content for all fields in a single LLM call.
    |
    */

    'entry_generator' => env('STATAMIC_AI_ASSISTANT_ENTRY_GENERATOR', true),

    'generator_max_tokens' => env('STATAMIC_AI_ASSISTANT_GENERATOR_MAX_TOKENS', 4000),

    'prompt_generator_preface' => env(
        'STATAMIC_AI_ASSISTANT_GENERATOR_PREFACE',
        'You are a CMS content creation assistant. Your task is to generate structured content for a website entry. You must respond with ONLY a valid JSON object. No markdown code fences, no commentary, no explanation — just the raw JSON.'
    ),

    /*
    |--------------------------------------------------------------------------
    | Entry generator fallback link
    |--------------------------------------------------------------------------
    |
    | When the AI leaves a Statamic "link" field empty, the generator can set
    | it to a stable internal entry (e.g. home) so the CP always loads valid data.
    |
    */
    'generator_fallback_link' => [
        'enabled' => env('STATAMIC_AI_ASSISTANT_GENERATOR_FALLBACK_LINK', true),
        'collection' => env('STATAMIC_AI_ASSISTANT_FALLBACK_LINK_COLLECTION', 'pages'),
        'slug' => env('STATAMIC_AI_ASSISTANT_FALLBACK_LINK_SLUG', 'home'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Prompt URL fetching (Jina Reader)
    |--------------------------------------------------------------------------
    |
    | When the user prompt contains http(s) URLs, the server fetches readable
    | text via https://r.jina.ai/{your-url} and appends it to the LLM context.
    | Failures (blocked sites, timeouts) become user-visible warnings only.
    | Set JINA_API_KEY for higher rate limits (optional Bearer token).
    |
    */

    'prompt_url_fetch' => [
        'enabled' => env('STATAMIC_AI_ASSISTANT_PROMPT_URL_FETCH', true),
        'reader_base' => rtrim(env('STATAMIC_AI_ASSISTANT_JINA_READER_BASE', 'https://r.jina.ai'), '/'),
        'timeout' => max(5, (int) env('STATAMIC_AI_ASSISTANT_JINA_TIMEOUT', 25)),
        'max_urls' => max(1, (int) env('STATAMIC_AI_ASSISTANT_JINA_MAX_URLS', 5)),
        'max_chars_per_url' => max(1000, (int) env('STATAMIC_AI_ASSISTANT_JINA_MAX_CHARS', 12000)),
        'max_total_chars' => max(5000, (int) env('STATAMIC_AI_ASSISTANT_JINA_MAX_TOTAL_CHARS', 40000)),
        'api_key' => env('JINA_API_KEY'),
    ],

];
