<?php

namespace BoldWeb\StatamicAiAssistant;

use BoldWeb\StatamicAiAssistant\Controllers\PromptController;
use BoldWeb\StatamicAiAssistant\Fieldtypes\AiTextarea;
use BoldWeb\StatamicAiAssistant\Fieldtypes\AiText;
use Illuminate\Support\Facades\Route;
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
  ];

  public function bootAddon()
  {
    $this->loadJsonTranslationsFrom(__DIR__ . '/../resources/lang');

    $this->mergeConfigFrom(
      __DIR__ . '/../config/statamic-ai-assistant.php',
      'statamic-ai-assistant'
    );

    // Publishable config, we use Forma to populate this properly within the CP
    $this->publishes([
      __DIR__ . '/../config/statamic-ai-assistant.php' => config_path('statamic-ai-assistant.php')
    ], 'statamic-ai-assistant');

    // ROUTES 
    // Route that accepts the prompt from the Text fieldtype, then returns the response to the editor
    $this->registerCpRoutes(function () {
      Route::post('/prompt', [PromptController::class, 'handle']);
    });

    // Route for HTML refactoring
    $this->registerCpRoutes(function () {
      Route::post('/promptHtmlrefactor', [PromptController::class, 'handleHtmlRefactor']);
    });

    // Route for text refactoring
    $this->registerCpRoutes(function () {
      Route::post('/promptrefactor', [PromptController::class, 'handleRefactor']);
    });

    //Route to get the languages defined
    $this->registerCpRoutes(function () {
      Route::get('/getLocalizations', [PromptController::class, 'getLocalizations']);
    });

    // we provide the config value to the client side
    Statamic::provideToScript(['translationsActiv' => config('statamic-ai-assistant.translations')]);
  }
}
