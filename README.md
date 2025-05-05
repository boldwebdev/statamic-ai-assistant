# Statamic Ai Assistant

> Statamic Ai Assistant is a Statamic addon that use API calls within custom fieldtypes and Bard to provide AI text generation and refactoring to the users.

## Supported API

- Infomaniak (CH, respect the data of your clients) - *Default*
- Groq (USA, get the speed)
- ...more to come


## Features

- Allow the usage of 'Ai Textarea' custom component in Statamic
- Allow the usage of 'Ai Text' custom component in Statamic
- Upgrade Bard by adding the AI asisstant buttons (generation and translation) directly in Bard
- Multilang supported (CP panel addon translations in EN/DE/FR/IT for now)

## How to Install

You can install this addon via Composer:

``` bash
composer require bold-web/statamic-ai-assistant
```

## How to Use
The plugin use per default Infomaniak API, a  Swiss solution🇨🇭 respecting the data of your users and the planet. However if you want to switch to a faster generation with a US based company this plugin also comes with Groq API support.

### Infomaniak API
1. Create an [Infomaniak](https://manager.infomaniak.com) account and generate an API token with access to your AI Tools. Define it in your .env file as ```INFOMANIAK_API_TOKEN``` 
2. Go to your dashboard computing->AI tools and define the `INFOMANIAK_PRODUCT_ID` in your .env file (or use their API to get the product id: https://developer.infomaniak.com/docs/api/get/1/ai)
   >tips for GET request: Just use postman with your api key in the Authorization header 'Bearer your-api-key' to get the product id

### Groq API
1. Create a [groq](https://groq.com/) account and generate an API key. Define it in your .env file as ```GROQ_API_KEY```
2. Add `STATAMIC_AI_ASSISTANT_PROVIDER_NAME='groq'` in your .env file to use groq

### Translations
We decided to integrate a translation functionnality that use the LLM to translate the texts on multi-site.
You can disable this feature with STATAMIC_AI_ASSISTANT_TRANSLATIONS=false in your .env file.

> We decided to use the LLM for the translations in the v1 for ease of use, but a Deepl integration is planned in the futur.

### Customization in your .env file

- You can override GROQ_MODEL with [the available model you want to use](https://console.groq.com/docs/models) / default is llama-3.1-8b-instant
- You can override INFOMANIAK_MODEL with [the available model you want to use](https://www.infomaniak.com/fr/hebergement/ai-tools/open-source-models) / default is mistral24b
- You can override STATAMIC_AI_ASSISTANT_PREFACE for your custom needs.
  > default preface of the addon: 'You are a seasoned SEO expert who writes clear, professional, seo friendly and engaging articles in plain text. Your task is to produce fully readable articles for an end user. Do not include any markdown formatting, HTML tags, header tags, meta descriptions, or any other markup. Write only the final article text in plain text, ensuring it is clear, direct, and free of extraneous formatting elements.'
- you can override STATAMIC_AI_ASSISTANT_REFACTOR_PREFACE
  > default refactor preface of the addon: 'You are a professional text refactoring expert. Your only task is to transform the provided text according to the specific instructions given by the user. Output solely the final refactored text in plain text, DO NOT INCLUDE the user instructions, any commentary, or any additional text. Preserve the original language and maintain a similar length to the input text unless instructed otherwise.'
- you can override STATAMIC_AI_ASSISTANT_HTML_REFACTOR_PREFACE
  > default refactor preface of the addon: 'You are a professional text refactoring expert. Your only task is to transform the provided HTML text according to the specific instructions given by the user. Output solely the final refactored HTML! DO NOT INCLUDE the user instructions, any commentary, or any additional text. ALWAYS RENDER the output as valid HTML. Keep all links intact unless the user specifically instructs you to modify them, and always strive to preserve the original HTML structure unless instructed otherwise.ALWAYS update lorem ipsum. Preserve the original language and maintain a similar length to the input text unless otherwise specified.'
- You can override TEMPERATURE
- You can override MAX_TOKENS
- You can disable the translation functionality with STATAMIC_AI_ASSISTANT_TRANSLATIONS=false

## Upgrade Request
If you face issues or have improvements ideas do not hesitate to send us an email at [boldwebdev+statamic@gmail.com](mailto:boldwebdev+statamic@gmail.com). We’ll read all emails, but please understand that this package is maintained as a side project for us, so we may not get back to you directly. Thank you for your understanding ❤️ 