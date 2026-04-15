<?php

use BoldWeb\StatamicAiAssistant\Controllers\TranslationController;
use Illuminate\Support\Facades\Route;

Route::prefix('ai-translations')->name('statamic-ai-assistant.')->group(function () {
    Route::get('/', function () {
        return view('statamic-ai-assistant::translations');
    })->name('translations');

    Route::post('/entry', [TranslationController::class, 'translateEntry'])->name('translate.entry');
    Route::post('/bulk', [TranslationController::class, 'translateBulk'])->name('translate.bulk');
    Route::post('/conflict-check', [TranslationController::class, 'conflictCheck'])->name('translate.conflict-check');
    Route::post('/field', [TranslationController::class, 'translateField'])->name('translate.field');
    Route::get('/progress/{batchId}', [TranslationController::class, 'progress'])->name('translate.progress');
    Route::get('/status', [TranslationController::class, 'status'])->name('translate.status');
    Route::get('/deepl-usage', [TranslationController::class, 'deeplUsage'])->name('translate.deepl-usage');
    Route::get('/collection-entries', [TranslationController::class, 'collectionEntries'])->name('translate.collection-entries');
    Route::get('/navigation-entries', [TranslationController::class, 'navigationEntries'])->name('translate.navigation-entries');
    Route::post('/navigation-sync', [TranslationController::class, 'navigationSync'])->name('translate.navigation-sync');
});
