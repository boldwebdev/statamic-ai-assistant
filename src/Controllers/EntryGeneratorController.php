<?php

namespace BoldWeb\StatamicAiAssistant\Controllers;

use BoldWeb\StatamicAiAssistant\Services\AbstractAiService;
use BoldWeb\StatamicAiAssistant\Services\EntryGenerationPlanner;
use BoldWeb\StatamicAiAssistant\Services\EntryGeneratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Statamic\Facades\Collection;
use Statamic\Facades\Site;

class EntryGeneratorController
{
    private EntryGeneratorService $generator;

    private AbstractAiService $aiService;

    private EntryGenerationPlanner $planner;

    public function __construct(
        EntryGeneratorService $generator,
        AbstractAiService $aiService,
        EntryGenerationPlanner $planner,
    ) {
        $this->generator = $generator;
        $this->aiService = $aiService;
        $this->planner = $planner;
    }

    /**
     * Return available collections and their blueprints.
     */
    public function collections(): JsonResponse
    {
        return response()->json(['collections' => $this->generator->getCollectionsCatalog()]);
    }

    /**
     * Return the field schema for a given collection + blueprint.
     */
    public function blueprintFields(Request $request): JsonResponse
    {
        $data = $request->validate([
            'collection' => 'required|string',
            'blueprint' => 'nullable|string',
        ]);

        $collection = Collection::findByHandle($data['collection']);

        if (! $collection) {
            return response()->json(['error' => __('Collection not found.')], 404);
        }

        $visible = $collection->entryBlueprints()->reject->hidden();

        $blueprint = $data['blueprint']
            ? $visible->keyBy->handle()->get($data['blueprint'])
            : $visible->first();

        if (! $blueprint) {
            return response()->json(['error' => __('Blueprint not found.')], 404);
        }

        $schema = $this->generator->getFieldSchemaForPreview($blueprint);

        return response()->json([
            'blueprint' => $blueprint->handle(),
            'fields' => $schema,
        ]);
    }

    /**
     * Generate content from a prompt (does NOT create the entry yet).
     */
    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'collection' => 'nullable|string',
            'blueprint' => 'nullable|string',
            'prompt' => 'required|string|min:10',
            'attachment' => 'nullable|file|mimes:pdf,txt|max:10240',
            'auto_resolve_target' => 'nullable|boolean',
        ]);

        $attachmentContent = null;

        if ($file = $request->file('attachment')) {
            $attachmentContent = $this->extractFileContent($file);
        }

        $autoResolve = $request->boolean('auto_resolve_target');

        if (! $autoResolve && ! $request->filled('collection')) {
            return response()->json(['error' => __('Please select a collection.')], 422);
        }

        $locale = optional(Site::selected())->handle() ?: Site::default()->handle();

        try {
            if ($autoResolve) {
                $resolved = $this->generator->resolveTargetFromPrompt(
                    $data['prompt'],
                    $attachmentContent,
                );
                $collectionHandle = $resolved['collection'];
                $blueprintHandle = $resolved['blueprint'];
            } else {
                $collectionHandle = (string) $request->input('collection');
                $blueprintHandle = (string) ($request->input('blueprint') ?? '');
            }

            $result = $this->generator->generateContent(
                $collectionHandle,
                $blueprintHandle,
                $data['prompt'],
                $locale,
                $attachmentContent,
            );

            return response()->json([
                'success' => true,
                'data' => $result['data'],
                'displayData' => $result['displayData'],
                'warnings' => $result['warnings'],
                'resolved_collection' => $collectionHandle,
                'resolved_blueprint' => $blueprintHandle,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            Log::error('Entry generation failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

            return response()->json(['error' => __('Entry generation failed. Please try again.')], 500);
        }
    }

    /**
     * Streaming endpoint that supports multi-entry generation.
     *
     * Phase 1 — planning: ask the LLM to decompose the user request into N independent entries.
     * Phase 2 — fan-out: generate each entry sequentially, reusing the existing single-entry pipeline.
     *
     * NDJSON event stream:
     *   {type:"planning"}
     *   {type:"plan", entries:[{id, index, collection, blueprint, label, prompt, collection_title, blueprint_title}], warnings:[…]}
     *   {type:"entry_start", id, index}
     *   {type:"entry_token", id, text}                              // raw delta — used by UI for progress estimation
     *   {type:"entry_result", id, index, success:true, data, displayData, warnings, collection, blueprint, label}
     *   {type:"entry_error", id, index, message}
     *   {type:"done", success:true}
     *   {type:"error", message}                                     // fatal pre-plan error
     */
    public function generateStream(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'collection' => 'nullable|string',
            'blueprint' => 'nullable|string',
            'prompt' => 'required|string|min:10',
            'attachment' => 'nullable|file|mimes:pdf,txt|max:10240',
            'auto_resolve_target' => 'nullable|boolean',
        ]);

        $attachmentContent = null;

        if ($file = $request->file('attachment')) {
            $attachmentContent = $this->extractFileContent($file);
        }

        $autoResolve = $request->boolean('auto_resolve_target');

        if (! $autoResolve && ! $request->filled('collection')) {
            return response()->stream(function () {
                echo json_encode(['type' => 'error', 'message' => __('Please select a collection.')], JSON_UNESCAPED_UNICODE)."\n";
                @ob_flush();
                flush();
            }, 422, $this->ndjsonStreamHeaders());
        }

        $locale = optional(Site::selected())->handle() ?: Site::default()->handle();

        return response()->stream(function () use ($request, $data, $attachmentContent, $autoResolve, $locale) {
            $emit = static function (array $payload): void {
                echo json_encode($payload, JSON_UNESCAPED_UNICODE)."\n";
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();
            };

            try {
                if ($autoResolve) {
                    // Multi-entry planning path: let the planner decide collection(s) AND split into N briefs.
                    $emit(['type' => 'planning']);

                    $plan = $this->planner->plan($data['prompt'], $attachmentContent, $locale);
                    $planEntries = $this->decoratePlanEntries($plan['entries']);

                    $emit([
                        'type' => 'plan',
                        'entries' => $planEntries,
                        'warnings' => $plan['warnings'],
                    ]);
                } else {
                    // Manual mode (full-page step-1 picker): always single-entry, no planner.
                    $collectionHandle = (string) $request->input('collection');
                    $blueprintHandle = (string) ($request->input('blueprint') ?? '');

                    $planEntries = $this->decoratePlanEntries([[
                        'collection' => $collectionHandle,
                        'blueprint' => $blueprintHandle,
                        'prompt' => $data['prompt'],
                        'label' => Str::limit($data['prompt'], 60),
                    ]]);

                    $emit([
                        'type' => 'plan',
                        'entries' => $planEntries,
                        'warnings' => [],
                    ]);
                }

                foreach ($planEntries as $idx => $planEntry) {
                    $entryId = $planEntry['id'];

                    $emit(['type' => 'entry_start', 'id' => $entryId, 'index' => $idx]);

                    try {
                        $result = $this->generator->generateContent(
                            $planEntry['collection'],
                            $planEntry['blueprint'],
                            $planEntry['prompt'],
                            $locale,
                            $attachmentContent,
                            static function (string $delta) use ($emit, $entryId): void {
                                if ($delta === '') {
                                    return;
                                }
                                $emit(['type' => 'entry_token', 'id' => $entryId, 'text' => $delta]);
                            },
                        );

                        $emit([
                            'type' => 'entry_result',
                            'id' => $entryId,
                            'index' => $idx,
                            'success' => true,
                            'data' => $result['data'],
                            'displayData' => $result['displayData'],
                            'warnings' => $result['warnings'],
                            'collection' => $planEntry['collection'],
                            'blueprint' => $planEntry['blueprint'],
                            'label' => $planEntry['label'],
                        ]);
                    } catch (\Throwable $e) {
                        Log::warning('Entry generation failed for one item', [
                            'collection' => $planEntry['collection'],
                            'blueprint' => $planEntry['blueprint'],
                            'error' => $e->getMessage(),
                        ]);

                        $emit([
                            'type' => 'entry_error',
                            'id' => $entryId,
                            'index' => $idx,
                            'message' => $e instanceof \RuntimeException
                                ? $e->getMessage()
                                : __('Entry generation failed. Please try again.'),
                        ]);
                    }
                }

                $emit(['type' => 'done', 'success' => true]);
            } catch (\RuntimeException $e) {
                $emit(['type' => 'error', 'message' => $e->getMessage()]);
            } catch (\Throwable $e) {
                Log::error('Entry generation stream failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
                $emit(['type' => 'error', 'message' => __('Entry generation failed. Please try again.')]);
            }
        }, 200, $this->ndjsonStreamHeaders());
    }

    /**
     * Add a stable id and human collection/blueprint titles to each plan entry.
     *
     * @param  array<int, array{collection: string, blueprint: string, prompt: string, label: string}>  $entries
     * @return array<int, array{id: string, collection: string, blueprint: string, prompt: string, label: string, collection_title: string, blueprint_title: string}>
     */
    private function decoratePlanEntries(array $entries): array
    {
        $catalog = $this->generator->getCollectionsCatalog();
        $titleMap = [];
        foreach ($catalog as $row) {
            $bps = [];
            foreach (($row['blueprints'] ?? []) as $bp) {
                $bps[$bp['handle'] ?? ''] = $bp['title'] ?? '';
            }
            $titleMap[$row['handle'] ?? ''] = ['title' => $row['title'] ?? '', 'blueprints' => $bps];
        }

        $decorated = [];
        foreach ($entries as $entry) {
            $coll = $entry['collection'] ?? '';
            $bp = $entry['blueprint'] ?? '';
            $decorated[] = [
                'id' => (string) Str::uuid(),
                'collection' => $coll,
                'blueprint' => $bp,
                'prompt' => $entry['prompt'] ?? '',
                'label' => $entry['label'] ?? '',
                'collection_title' => $titleMap[$coll]['title'] ?? $coll,
                'blueprint_title' => $titleMap[$coll]['blueprints'][$bp] ?? $bp,
            ];
        }

        return $decorated;
    }

    /**
     * @return array<string, string>
     */
    private function ndjsonStreamHeaders(): array
    {
        return [
            'Content-Type' => 'application/x-ndjson; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'X-Accel-Buffering' => 'no',
        ];
    }

    /**
     * Create the entry from previously generated data.
     */
    public function createEntry(Request $request): JsonResponse
    {
        $data = $request->validate([
            'collection' => 'required|string',
            'blueprint' => 'nullable|string',
            'data' => 'required|array',
        ]);

        $locale = optional(Site::selected())->handle() ?: Site::default()->handle();

        try {
            $entry = $this->generator->saveEntry(
                $data['collection'],
                $data['blueprint'] ?? '',
                $locale,
                $data['data'],
            );

            return response()->json([
                'success' => true,
                'entry_id' => $entry->id(),
                'edit_url' => $entry->editUrl(),
                'title' => $entry->value('title') ?? '',
            ]);
        } catch (\Exception $e) {
            Log::error('Entry creation failed', ['error' => $e->getMessage()]);

            return response()->json(['error' => __('Entry creation failed. Please try again.')], 500);
        }
    }

    /**
     * Regenerate a single field value.
     */
    public function regenerateField(Request $request): JsonResponse
    {
        $data = $request->validate([
            'field_type' => 'required|string',
            'current_value' => 'nullable|string',
            'prompt' => 'required|string',
        ]);

        try {
            if ($data['field_type'] === 'bard') {
                $content = $this->aiService->generateHtmlRefactorFromPrompt(
                    $data['current_value'] ?? '',
                    $data['prompt'],
                );
            } else {
                $content = $this->aiService->generateContentFromPrompt($data['prompt']);
            }

            return response()->json(['value' => $content]);
        } catch (\Exception $e) {
            Log::error('Field regeneration failed', ['error' => $e->getMessage()]);

            return response()->json(['error' => __('Field regeneration failed. Please try again.')], 500);
        }
    }

    /**
     * Extract text content from an uploaded file.
     */
    private function extractFileContent(\Illuminate\Http\UploadedFile $file): ?string
    {
        try {
            $extension = strtolower($file->getClientOriginalExtension());

            if ($extension === 'pdf') {
                $parser = new \Smalot\PdfParser\Parser;
                $pdf = $parser->parseFile($file->getRealPath());
                $content = $pdf->getText();
            } else {
                $content = file_get_contents($file->getRealPath());
            }

            if (! $content) {
                return null;
            }

            // Truncate to ~8000 words to stay within LLM context window
            $words = explode(' ', $content);

            if (count($words) > 8000) {
                $content = implode(' ', array_slice($words, 0, 8000))."\n\n[Content truncated...]";
            }

            return $content;
        } catch (\Exception $e) {
            Log::warning('PDF extraction failed', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
