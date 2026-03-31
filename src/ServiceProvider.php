<?php

namespace BoldWeb\StatamicAiAssistant;

use BoldWeb\StatamicAiAssistant\Actions\TranslateEntry;
use BoldWeb\StatamicAiAssistant\Controllers\PromptController;
use BoldWeb\StatamicAiAssistant\Controllers\TranslationController;
use BoldWeb\StatamicAiAssistant\Fieldtypes\AiText;
use BoldWeb\StatamicAiAssistant\Fieldtypes\AiTextarea;
use BoldWeb\StatamicAiAssistant\Fieldtypes\TranslationActionPreflight;
use BoldWeb\StatamicAiAssistant\Fieldtypes\TranslationTargetLanguages;
use BoldWeb\StatamicAiAssistant\Services\AbstractAiService;
use BoldWeb\StatamicAiAssistant\Services\DeeplService;
use BoldWeb\StatamicAiAssistant\Services\EntryReferenceResolver;
use BoldWeb\StatamicAiAssistant\Services\EntryTranslator;
use BoldWeb\StatamicAiAssistant\Services\GroqService;
use BoldWeb\StatamicAiAssistant\Services\InfomaniakService;
use BoldWeb\StatamicAiAssistant\Services\TranslationService;
use Illuminate\Support\Facades\Route;
use Statamic\Facades\CP\Nav;
use Statamic\Providers\AddonServiceProvider;
use Statamic\Statamic;

class ServiceProvider extends AddonServiceProvider
{
    protected $vite = [
        'input' => [
            'resources/js/app.js',
            'resources/css/app.css',
        ],
        'publicDirectory' => 'resources/dist',
    ];

    protected $fieldtypes = [
        AiTextarea::class,
        AiText::class,
        TranslationActionPreflight::class,
        TranslationTargetLanguages::class,
    ];

    protected $actions = [
        TranslateEntry::class,
    ];

    protected $routes = [
        'cp' => __DIR__.'/../routes/cp.php',
    ];

    protected $viewNamespace = 'statamic-ai-assistant';

    public function register(): void
    {
        parent::register();

        $this->app->singleton(AbstractAiService::class, function () {
            $provider = config('statamic-ai-assistant.provider_name');

            return $provider === 'infomaniak' ? new InfomaniakService() : new GroqService();
        });

        $this->app->singleton(DeeplService::class, function () {
            return new DeeplService();
        });

        $this->app->singleton(EntryReferenceResolver::class, function () {
            return new EntryReferenceResolver();
        });

        $this->app->singleton(EntryTranslator::class, function ($app) {
            return new EntryTranslator(
                $app->make(DeeplService::class),
                $app->make(EntryReferenceResolver::class),
            );
        });

        $this->app->singleton(TranslationService::class, function ($app) {
            return new TranslationService($app->make(EntryTranslator::class));
        });
    }

    public function bootAddon()
    {
        $this->loadJsonTranslationsFrom(__DIR__.'/../resources/lang');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'statamic-ai-assistant');

        $this->mergeConfigFrom(
            __DIR__.'/../config/statamic-ai-assistant.php',
            'statamic-ai-assistant'
        );

        $this->mergeConfigFrom(
            __DIR__.'/../config/deepl.php',
            'deepl'
        );

        $this->publishes([
            __DIR__.'/../config/statamic-ai-assistant.php' => config_path('statamic-ai-assistant.php'),
            __DIR__.'/../config/deepl.php' => config_path('deepl.php'),
        ], 'statamic-ai-assistant');

        // Legacy prompt routes
        $this->registerCpRoutes(function () {
            Route::post('/prompt', [PromptController::class, 'handle']);
        });

        $this->registerCpRoutes(function () {
            Route::post('/promptHtmlrefactor', [PromptController::class, 'handleHtmlRefactor']);
        });

        $this->registerCpRoutes(function () {
            Route::post('/promptrefactor', [PromptController::class, 'handleRefactor']);
        });

        $this->registerCpRoutes(function () {
            Route::get('/getLocalizations', [PromptController::class, 'getLocalizations']);
        });

        if (config('statamic-ai-assistant.translations', true)) {
            Nav::extend(function ($nav) {
                $nav->tools(__('Bulk translations'))
                    ->route('statamic-ai-assistant.translations')
                    ->icon('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m5 8 6 6"/><path d="m4 14 6-6 2-3"/><path d="M2 5h12"/><path d="M7 2h1"/><path d="m22 22-5-10-5 10"/><path d="M14 18h6"/></svg>');
            });
        }

        Statamic::provideToScript(['translationsActiv' => config('statamic-ai-assistant.translations')]);
    }
}
