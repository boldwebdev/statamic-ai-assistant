<?php

namespace BoldWeb\StatamicAiAssistant\Services;

use BoldWeb\StatamicAiAssistant\Jobs\GeneratePlannedEntryJob;
use BoldWeb\StatamicAiAssistant\Services\Migration\PreferredAssetPaths;
use BoldWeb\StatamicAiAssistant\Support\JsonObjectExtractor;
use BoldWeb\StatamicAiAssistant\Support\PlanEntryDecorator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Decompose a free-text user request into a list of independent entry plans.
 *
 * Each plan targets one collection + blueprint and carries a focused brief
 * so the existing single-entry generator can be reused per item.
 */
class EntryGenerationPlanner
{
    private AbstractAiService $aiService;

    private EntryGeneratorService $generator;

    private PromptUrlFetcher $promptUrlFetcher;

    private ?FigmaContentFetcher $figma;

    private ?EntryGenerationBatchService $batch;

    private ?PlanEntryDecorator $decorator;

    public function __construct(
        AbstractAiService $aiService,
        EntryGeneratorService $generator,
        PromptUrlFetcher $promptUrlFetcher,
        ?FigmaContentFetcher $figma = null,
        ?EntryGenerationBatchService $batch = null,
        ?PlanEntryDecorator $decorator = null,
    ) {
        $this->aiService = $aiService;
        $this->generator = $generator;
        $this->promptUrlFetcher = $promptUrlFetcher;
        $this->figma = $figma;
        $this->batch = $batch;
        $this->decorator = $decorator;
    }

    /**
     * Always returns at least one entry. When the LLM cannot produce a usable plan,
     * falls back to single-entry resolution so the existing flow keeps working.
     *
     * @param  callable(): void|null  $streamHeartbeat  Throttled NDJSON keepalive from the CP stream (proxies idle-timeout).
     * @return array{
     *   entries: array<int, array{collection: string, blueprint: string, prompt: string, label: string}>,
     *   warnings: string[],
     *   url_augmentation: array{appendix: string, warnings: array<int, string>, preferred: PreferredAssetPaths, appended_to_prompts: bool},
     * }
     */
    public function plan(string $prompt, ?string $attachmentContent = null, ?string $siteLocale = null, ?callable $streamHeartbeat = null): array
    {
        $catalog = $this->generator->getCollectionsCatalog();

        if ($catalog === []) {
            throw new \RuntimeException(__('No collections with blueprints are available.'));
        }

        $catalogJson = json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $maxEntries = max(1, min(500, (int) config('statamic-ai-assistant.bold_agent_max_plan_entries', 100)));

        $useUrlTool = (bool) config('statamic-ai-assistant.entry_generator_fetch_url_tool', true)
            && (bool) config('statamic-ai-assistant.prompt_url_fetch.enabled', true)
            && $this->aiService->supportsChatTools();

        $urlToolHint = $useUrlTool
            ? "URL HANDLING RULES — these take priority over any later JSON output rule:\n"
                ."1. If the user message references one or more http(s) URLs, you MUST call the **fetch_page_content** tool to retrieve them BEFORE producing JSON. Never guess how many entries to plan from a URL alone.\n"
                ."2. Use the fetched content to decide how many entries to create. If the URL is a homepage, sitemap, listing, or section index, fetch additional sub-pages as needed to enumerate every item the user asked about.\n"
                ."3. For requests like 'all pages of this website', 'every news on this page', or 'one entry per article', produce **one `entries[]` row per distinct article/item** you discovered (capped at {$maxEntries}). Each row must have its own `label` and `prompt` that cites **exactly one** canonical detail-page URL for that item. Never collapse many articles into a single plan row — the downstream generator creates **one CMS entry per plan row**.\n"
                ."4. You may call the tool multiple times. Pass **url** (full link) and **reason** (short: which planning step this supports).\n"
                ."5. ONLY after you have understood the source structure, respond with the JSON object — no markdown fences, no commentary.\n"
                ."---\n\n"
            : '';

        $system = $urlToolHint
            ."You are a Statamic CMS planner. The user describes one or more entries they want to create. "
            ."Split the request into a list of independent entries and pick the best collection + blueprint for each, drawn ONLY from the catalog provided.\n\n"
            ."Return ONLY a JSON object shaped like:\n"
            ."{\"entries\":[{\"collection\":\"<handle>\",\"blueprint\":\"<handle>\",\"label\":\"<2-6 word title>\",\"prompt\":\"<self-contained brief for this single entry>\"}]}\n\n"
            ."Rules:\n"
            ."- If the user asks for one entry, return exactly one item.\n"
            ."- If the user asks for several entries (\"create 2 pages…\", \"a blog post and a page about X\"), return one item per entry, in the order requested.\n"
            ."- If the user wants **one entry per article** (or per listing item), return **one item per article**, not one item that tells the generator to recreate the whole list.\n"
            ."- Cap the list at {$maxEntries} items even if more are requested.\n"
            ."- collection and blueprint MUST match the catalog exactly (case-sensitive). Do not invent handles.\n"
            ."- The blueprint MUST be one listed for the chosen collection.\n"
            ."- If unsure which collection fits, prefer the collection whose handle is \"pages\" if present; otherwise use the first catalog collection.\n"
            ."- The per-entry \"prompt\" must be a complete, self-contained brief in the user's language — include every detail from the user's request that pertains to this entry. Do not reference \"the other entry\" or rely on context outside the prompt.\n"
            ."- The \"label\" is a short human title (2-6 words) for the UI, in the user's language.\n"
            .$this->germanNoEszettPlannerRule($siteLocale)
            ."- Output JSON only. No markdown fences, no commentary.";

        $attachmentPart = $attachmentContent
            ? "\n\nAdditional context from an attached document (excerpt):\n".Str::limit($attachmentContent, 6000)
            : '';

        // When the entry generator will expose fetch_page_content as a tool, skip the
        // server-side URL pre-fetch here too. Otherwise the listing page would be
        // inlined into the planner context and re-passed to the entry generator,
        // leaving the LLM no reason to drill into detail pages.
        $urlAug = $useUrlTool
            ? ['appendix' => '', 'warnings' => [], 'preferred' => new PreferredAssetPaths]
            : $this->promptUrlFetcher->buildAugmentation($prompt);
        $figmaAug = $this->figma ? $this->figma->buildAugmentation($prompt) : ['appendix' => '', 'warnings' => []];

        $user = "Available collections and blueprints (JSON):\n{$catalogJson}\n\nUser request:\n{$prompt}{$urlAug['appendix']}{$figmaAug['appendix']}{$attachmentPart}";

        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];

        $warnings = [];
        $plannerToolWarnings = [];

        try {
            if ($useUrlTool) {
                $raw = $this->planWithToolLoop($messages, $prompt, $plannerToolWarnings, $streamHeartbeat);
            } else {
                $raw = $this->aiService->generateFromMessages($messages, 1024);
            }
        } catch (\Throwable) {
            return $this->singleEntryFallback($prompt, $attachmentContent, $warnings, $urlAug, $figmaAug);
        }

        foreach ($plannerToolWarnings as $w) {
            $warnings[] = $w;
        }

        if ($raw === null || trim($raw) === '') {
            return $this->singleEntryFallback($prompt, $attachmentContent, $warnings, $urlAug, $figmaAug);
        }

        try {
            $entries = $this->parseAndNormalize($raw, $catalog, $prompt, $warnings);
        } catch (\RuntimeException $e) {
            Log::warning('[entry-gen-tool] planner JSON parse failed; single-entry fallback', [
                'message' => $e->getMessage(),
                'raw_chars' => strlen($raw),
            ]);

            return $this->singleEntryFallback($prompt, $attachmentContent, $warnings, $urlAug, $figmaAug);
        }

        if ($entries === []) {
            return $this->singleEntryFallback($prompt, $attachmentContent, $warnings, $urlAug, $figmaAug);
        }

        if (count($entries) > $maxEntries) {
            $dropped = count($entries) - $maxEntries;
            $entries = array_slice($entries, 0, $maxEntries);
            $warnings[] = __(':n more entries were requested but only the first :max will be created. Ask again to create the rest.', [
                'n' => $dropped,
                'max' => $maxEntries,
            ]);
        }

        foreach ($urlAug['warnings'] as $w) {
            $warnings[] = $w;
        }

        foreach ($figmaAug['warnings'] as $w) {
            $warnings[] = $w;
        }

        $combinedAppendix = $urlAug['appendix'].$figmaAug['appendix'];

        if ($combinedAppendix !== '') {
            foreach ($entries as &$entry) {
                $entry['prompt'] = trim((string) ($entry['prompt'] ?? '')).$combinedAppendix;
            }
            unset($entry);
        }

        return [
            'entries' => $entries,
            'warnings' => $warnings,
            'url_augmentation' => $this->urlAugmentationForResponse($urlAug, $combinedAppendix !== ''),
        ];
    }

    /**
     * Agentic planner: runs inside PlanEntriesJob. Reads prompt/locale/attachment
     * from the batch session, lets the LLM call fetch_page_content + create_entry_job,
     * and dispatches one GeneratePlannedEntryJob per planned article in parallel.
     *
     * Throws so PlanEntriesJob can mark planning_failed; success path calls markPlanningComplete.
     */
    public function planAgentic(string $sessionId): void
    {
        if ($this->batch === null || $this->decorator === null) {
            throw new \RuntimeException('Planner agentic dependencies not bound (EntryGenerationBatchService / PlanEntryDecorator).');
        }

        if (! $this->aiService->supportsChatTools()) {
            throw new \RuntimeException(__('The configured AI provider does not support tool calls; cannot run BOLD agent planner.'));
        }

        $session = $this->batch->getSession($sessionId);
        if (! is_array($session)) {
            throw new \RuntimeException(__('Planner session not found.'));
        }

        $prompt = (string) ($session['prompt'] ?? '');
        $locale = (string) ($session['locale'] ?? '');
        $attachment = isset($session['attachment_content']) && is_string($session['attachment_content'])
            ? $session['attachment_content']
            : null;

        $catalog = $this->generator->getCollectionsCatalog();
        if ($catalog === []) {
            throw new \RuntimeException(__('No collections with blueprints are available.'));
        }

        $catalogJson = json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $cap = max(1, min(500, (int) config('statamic-ai-assistant.bold_agent_max_plan_entries', 100)));

        $system = "You are a Statamic CMS planner running asynchronously. The user described one or more entries they want to create. "
            ."Your job is to discover every individual entry they want and create it via tool calls. **Never produce JSON output yourself** — entries are created exclusively through `create_entry_job` tool calls.\n\n"
            ."WORKFLOW:\n"
            ."1. If the user message references one or more http(s) URLs, call **fetch_page_content** first to inspect them. Never guess content from a URL slug.\n"
            ."2. For listing/index URLs (homepages, news lists, sitemaps), enumerate every relevant detail page; you may fetch the same listing or its sub-pages multiple times.\n"
            ."3. As soon as you can identify a distinct entry the user wants, call **create_entry_job** for it — do not wait to plan all entries before dispatching. Each call enqueues a worker that starts generating immediately, in parallel.\n"
            ."4. Use one **create_entry_job** call per distinct article/item. Never collapse many articles into a single call. The cap is {$cap} create_entry_job calls per request.\n"
            ."5. Pick collection + blueprint **only** from the catalog below (handles must match exactly, case-sensitive). Each chosen blueprint must be one listed for that collection. If unsure, prefer the collection whose handle is `pages` if present; otherwise the first catalog collection.\n"
            ."6. Each entry's `prompt` MUST be a complete, self-contained brief in the user's language: include the canonical detail URL (when applicable), the topic, and any constraints from the user's request. Do not reference other entries.\n"
            ."7. The `label` is a short human title (2-6 words) for the UI in the user's language.\n"
            .$this->germanNoEszettPlannerRule($locale)
            ."8. When you have dispatched every entry the user asked for (or hit the cap), end your turn with a short plain-text summary like `Done — N entries dispatched.` (no JSON, no tool calls).\n"
            ."9. If the request is impossible (no usable URL, ambiguous intent that cannot be resolved by fetching), end your turn with a short plain-text explanation starting with `Cannot proceed:`.";

        $attachmentPart = $attachment
            ? "\n\nAdditional context from an attached document (excerpt):\n".Str::limit($attachment, 6000)
            : '';

        $user = "Available collections and blueprints (JSON):\n{$catalogJson}\n\nUser request:\n{$prompt}{$attachmentPart}";

        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];

        $heartbeat = function () use ($sessionId): void {
            // Cancellation poll between LLM rounds + tool calls; throwing exits the loop.
            if ($this->batch !== null && $this->batch->isCancelled($sessionId)) {
                throw new \RuntimeException((string) __('Cancelled.'));
            }
        };

        $toolWarnings = [];
        $this->planAgenticToolLoop(
            $messages,
            $prompt,
            $sessionId,
            $catalog,
            $cap,
            $toolWarnings,
            $heartbeat,
        );

        foreach ($toolWarnings as $w) {
            if (is_string($w) && $w !== '') {
                $this->batch->appendPlannerWarning($sessionId, $w);
            }
        }

        $this->batch->markPlanningComplete($sessionId);
    }

    /**
     * Multi-turn planner completion: lets the model call fetch_page_content
     * to inspect the source site before deciding how many entries to plan.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, string>  $toolWarningsOut
     * @param  callable(): void|null  $streamHeartbeat
     */
    private function planWithToolLoop(array $messages, string $userPrompt, array &$toolWarningsOut, ?callable $streamHeartbeat = null): string
    {
        $tools = [$this->promptUrlFetcher->chatToolDefinition()];
        $maxRounds = (int) config('statamic-ai-assistant.entry_generator_tool_max_rounds', 120);
        $maxFetches = (int) config('statamic-ai-assistant.entry_generator_tool_max_fetches', 100);
        // Planner JSON can be huge (many URLs + briefs); keep separate from per-entry generator_max_tokens.
        $maxTokens = max(4096, (int) config('statamic-ai-assistant.entry_generator_planner_max_output_tokens', 12000));
        $fetches = 0;

        $promptHasUrl = (bool) preg_match('~\bhttps?://[^\s<>\]\}\)\"\'`]+~iu', $userPrompt);
        $working = $messages;

        for ($round = 0; $round < $maxRounds; $round++) {
            $streamHeartbeat?->__invoke();

            $forceTool = $promptHasUrl && $fetches === 0;
            $toolChoice = $forceTool
                ? ['type' => 'function', 'function' => ['name' => 'fetch_page_content']]
                : 'auto';

            $data = $this->aiService->createChatCompletion($working, $maxTokens, $tools, $toolChoice, $streamHeartbeat);
            $choice = $data['choices'][0] ?? null;
            $msg = is_array($choice) ? ($choice['message'] ?? null) : null;

            if (! is_array($msg)) {
                throw new \RuntimeException(__('Unexpected planner response shape.'));
            }

            $toolCalls = $msg['tool_calls'] ?? null;
            $hasToolCalls = is_array($toolCalls) && $toolCalls !== [];

            if ($forceTool && ! $hasToolCalls) {
                Log::warning('[entry-gen-tool] planner forced tool call was IGNORED', [
                    'round' => $round,
                    'tool_choice_sent' => $toolChoice,
                ]);
            }

            if ($hasToolCalls) {
                $working[] = [
                    'role' => 'assistant',
                    'content' => array_key_exists('content', $msg) ? $msg['content'] : null,
                    'tool_calls' => $toolCalls,
                ];

                foreach ($toolCalls as $tc) {
                    if (! is_array($tc)) {
                        continue;
                    }

                    $id = isset($tc['id']) && is_string($tc['id']) ? $tc['id'] : '';
                    if (($tc['type'] ?? '') !== 'function' || $id === '') {
                        continue;
                    }

                    $fn = $tc['function'] ?? [];
                    $name = isset($fn['name']) && is_string($fn['name']) ? $fn['name'] : '';
                    $args = isset($fn['arguments']) && is_string($fn['arguments']) ? $fn['arguments'] : '{}';

                    if ($name !== 'fetch_page_content') {
                        $working[] = [
                            'role' => 'tool',
                            'tool_call_id' => $id,
                            'content' => json_encode(['ok' => false, 'error' => 'unknown tool: '.$name], JSON_UNESCAPED_UNICODE),
                        ];

                        continue;
                    }

                    if ($fetches >= $maxFetches) {
                        Log::warning('[entry-gen-tool] planner fetch limit reached', [
                            'fetches' => $fetches,
                            'max_fetches' => $maxFetches,
                        ]);
                        $toolWarningsOut[] = __('URL fetch tool: maximum number of fetches for the planner was reached.');
                        $working[] = [
                            'role' => 'tool',
                            'tool_call_id' => $id,
                            'content' => json_encode([
                                'ok' => false,
                                'error' => 'fetch_limit_reached',
                                'reason_echo' => '',
                                'url' => '',
                            ], JSON_UNESCAPED_UNICODE),
                        ];

                        continue;
                    }

                    $fetches++;
                    $working[] = [
                        'role' => 'tool',
                        'tool_call_id' => $id,
                        'content' => $this->promptUrlFetcher->executeChatTool($args, null, $toolWarningsOut),
                    ];
                    $streamHeartbeat?->__invoke();
                }

                continue;
            }

            $content = $msg['content'] ?? '';
            if (is_string($content) && trim($content) !== '') {
                Log::info('[entry-gen-tool] planner done', [
                    'rounds' => $round + 1,
                    'fetches' => $fetches,
                ]);

                return $content;
            }

            throw new \RuntimeException(__('Planner returned no usable text after tool use.'));
        }

        Log::error('[entry-gen-tool] planner tool loop exceeded max rounds', [
            'max_rounds' => $maxRounds,
            'fetches' => $fetches,
        ]);
        throw new \RuntimeException(__('Planner stopped: too many tool rounds.'));
    }

    /**
     * Agentic tool loop: same shape as planWithToolLoop but exposes both
     * fetch_page_content and create_entry_job. Each create_entry_job call
     * appends a row to the batch session and dispatches GeneratePlannedEntryJob.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array{handle: string, title: string, blueprints: array<int, array{handle: string, title: string}>}>  $catalog
     * @param  array<int, string>  $toolWarningsOut
     * @param  callable(): void|null  $streamHeartbeat
     */
    private function planAgenticToolLoop(
        array $messages,
        string $userPrompt,
        string $sessionId,
        array $catalog,
        int $cap,
        array &$toolWarningsOut,
        ?callable $streamHeartbeat = null,
    ): void {
        $tools = [
            $this->promptUrlFetcher->chatToolDefinition(),
            $this->createEntryJobToolDefinition($cap),
        ];
        $maxRounds = (int) config('statamic-ai-assistant.entry_generator_tool_max_rounds', 120);
        $maxFetches = (int) config('statamic-ai-assistant.entry_generator_tool_max_fetches', 100);
        $maxTokens = max(4096, (int) config('statamic-ai-assistant.entry_generator_planner_max_output_tokens', 12000));
        $fetches = 0;
        $created = 0;

        $promptHasUrl = (bool) preg_match('~\bhttps?://[^\s<>\]\}\)\"\'`]+~iu', $userPrompt);
        $working = $messages;

        for ($round = 0; $round < $maxRounds; $round++) {
            $streamHeartbeat?->__invoke();

            $forceTool = $promptHasUrl && $fetches === 0 && $created === 0;
            $toolChoice = $forceTool
                ? ['type' => 'function', 'function' => ['name' => 'fetch_page_content']]
                : 'auto';

            $data = $this->aiService->createChatCompletion($working, $maxTokens, $tools, $toolChoice, $streamHeartbeat);
            $choice = $data['choices'][0] ?? null;
            $msg = is_array($choice) ? ($choice['message'] ?? null) : null;

            if (! is_array($msg)) {
                throw new \RuntimeException(__('Unexpected planner response shape.'));
            }

            $toolCalls = $msg['tool_calls'] ?? null;
            $hasToolCalls = is_array($toolCalls) && $toolCalls !== [];

            if ($forceTool && ! $hasToolCalls) {
                Log::warning('[entry-gen-tool] agentic planner forced tool call IGNORED', [
                    'round' => $round,
                    'tool_choice_sent' => $toolChoice,
                ]);
            }

            if ($hasToolCalls) {
                $working[] = [
                    'role' => 'assistant',
                    'content' => array_key_exists('content', $msg) ? $msg['content'] : null,
                    'tool_calls' => $toolCalls,
                ];

                foreach ($toolCalls as $tc) {
                    if (! is_array($tc)) {
                        continue;
                    }

                    $id = isset($tc['id']) && is_string($tc['id']) ? $tc['id'] : '';
                    if (($tc['type'] ?? '') !== 'function' || $id === '') {
                        continue;
                    }

                    $fn = $tc['function'] ?? [];
                    $name = isset($fn['name']) && is_string($fn['name']) ? $fn['name'] : '';
                    $args = isset($fn['arguments']) && is_string($fn['arguments']) ? $fn['arguments'] : '{}';

                    if ($name === 'fetch_page_content') {
                        if ($fetches >= $maxFetches) {
                            Log::warning('[entry-gen-tool] agentic planner fetch limit reached', [
                                'fetches' => $fetches,
                                'max_fetches' => $maxFetches,
                            ]);
                            $toolWarningsOut[] = __('URL fetch tool: maximum number of fetches for the planner was reached.');
                            $working[] = [
                                'role' => 'tool',
                                'tool_call_id' => $id,
                                'content' => json_encode([
                                    'ok' => false,
                                    'error' => 'fetch_limit_reached',
                                    'reason_echo' => '',
                                    'url' => '',
                                ], JSON_UNESCAPED_UNICODE),
                            ];

                            continue;
                        }

                        $fetches++;
                        $working[] = [
                            'role' => 'tool',
                            'tool_call_id' => $id,
                            'content' => $this->promptUrlFetcher->executeChatTool($args, null, $toolWarningsOut),
                        ];
                        $streamHeartbeat?->__invoke();

                        continue;
                    }

                    if ($name === 'create_entry_job') {
                        $result = $this->handleCreateEntryJobToolCall($args, $sessionId, $catalog, $cap, $created);
                        $working[] = [
                            'role' => 'tool',
                            'tool_call_id' => $id,
                            'content' => json_encode($result, JSON_UNESCAPED_UNICODE),
                        ];

                        if (($result['ok'] ?? false) === true) {
                            $created++;
                        }

                        $streamHeartbeat?->__invoke();

                        continue;
                    }

                    $working[] = [
                        'role' => 'tool',
                        'tool_call_id' => $id,
                        'content' => json_encode(['ok' => false, 'error' => 'unknown tool: '.$name], JSON_UNESCAPED_UNICODE),
                    ];
                }

                continue;
            }

            $content = $msg['content'] ?? '';
            if (is_string($content) && trim($content) !== '') {
                Log::info('[entry-gen-tool] agentic planner done', [
                    'rounds' => $round + 1,
                    'fetches' => $fetches,
                    'entries_created' => $created,
                ]);

                if ($created === 0) {
                    throw new \RuntimeException(trim($content) !== ''
                        ? trim($content)
                        : (string) __('Planner ended without creating any entries.'));
                }

                return;
            }

            throw new \RuntimeException(__('Planner returned no usable text after tool use.'));
        }

        Log::error('[entry-gen-tool] agentic planner tool loop exceeded max rounds', [
            'max_rounds' => $maxRounds,
            'fetches' => $fetches,
            'entries_created' => $created,
        ]);

        if ($created === 0) {
            throw new \RuntimeException(__('Planner stopped: too many tool rounds.'));
        }

        // Some entries were dispatched — surface a warning instead of failing the whole batch.
        $toolWarningsOut[] = __('Planner stopped after :n entries (too many tool rounds). Re-run if you need more.', ['n' => $created]);
    }

    /**
     * @return array{type: string, function: array<string, mixed>}
     */
    private function createEntryJobToolDefinition(int $cap): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'create_entry_job',
                'description' => 'Queue ONE entry for asynchronous generation. Call this incrementally — once per distinct article/item the user asked for. The card appears in the user\'s drawer the moment this is called, and a worker starts generating its content in parallel. Cap: '.$cap.' calls per request.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'collection' => [
                            'type' => 'string',
                            'description' => 'Collection handle from the catalog (case-sensitive).',
                        ],
                        'blueprint' => [
                            'type' => 'string',
                            'description' => 'Blueprint handle, must be one of the blueprints listed for the chosen collection.',
                        ],
                        'label' => [
                            'type' => 'string',
                            'description' => 'Short 2-6 word title for the UI card, in the user\'s language.',
                        ],
                        'prompt' => [
                            'type' => 'string',
                            'description' => 'Self-contained brief for this single entry, in the user\'s language. Include the canonical detail URL when applicable so the worker can fetch the source.',
                        ],
                    ],
                    'required' => ['collection', 'blueprint', 'label', 'prompt'],
                ],
            ],
        ];
    }

    /**
     * @param  array<int, array{handle: string, title: string, blueprints: array<int, array{handle: string, title: string}>}>  $catalog
     * @return array{ok: bool, id?: string, count?: int, error?: string}
     */
    private function handleCreateEntryJobToolCall(string $argumentsJson, string $sessionId, array $catalog, int $cap, int $alreadyCreated): array
    {
        if ($this->batch === null || $this->decorator === null) {
            return ['ok' => false, 'error' => 'planner_dependencies_missing'];
        }

        if ($alreadyCreated >= $cap) {
            return ['ok' => false, 'error' => 'cap_reached', 'count' => $alreadyCreated];
        }

        try {
            $args = json_decode($argumentsJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            Log::warning('[entry-gen-tool] agentic planner: invalid create_entry_job args', [
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'error' => 'invalid_arguments_json'];
        }

        if (! is_array($args)) {
            return ['ok' => false, 'error' => 'invalid_arguments_shape'];
        }

        $collection = isset($args['collection']) && is_string($args['collection']) ? trim($args['collection']) : '';
        $blueprint = isset($args['blueprint']) && is_string($args['blueprint']) ? trim($args['blueprint']) : '';
        $entryPrompt = isset($args['prompt']) && is_string($args['prompt']) ? trim($args['prompt']) : '';
        $label = isset($args['label']) && is_string($args['label']) ? trim($args['label']) : '';

        if ($collection === '' || $entryPrompt === '') {
            return ['ok' => false, 'error' => 'missing_required_fields'];
        }

        $validated = $this->validateAndCoerceTarget($catalog, $collection, $blueprint);
        if ($validated === null) {
            return ['ok' => false, 'error' => 'invalid_target', 'collection' => $collection, 'blueprint' => $blueprint];
        }

        if ($label === '') {
            $label = Str::limit(strip_tags($entryPrompt), 60);
        }

        $decorated = $this->decorator->decorateOne([
            'collection' => $validated['collection'],
            'blueprint' => $validated['blueprint'],
            'prompt' => $entryPrompt,
            'label' => $label,
        ]);

        $added = $this->batch->addPlannedEntry($sessionId, $decorated, $cap);
        if (! $added) {
            return ['ok' => false, 'error' => 'cap_or_session_rejected', 'count' => $alreadyCreated];
        }

        GeneratePlannedEntryJob::dispatch($sessionId, (string) $decorated['id']);

        return [
            'ok' => true,
            'id' => (string) $decorated['id'],
            'count' => $alreadyCreated + 1,
        ];
    }

    /**
     * @param  array{appendix: string, warnings: array<int, string>, preferred: PreferredAssetPaths}  $urlAug
     */
    private function urlAugmentationForResponse(array $urlAug, bool $appendedToPrompts): array
    {
        return [
            'appendix' => $urlAug['appendix'],
            'warnings' => $urlAug['warnings'],
            'preferred' => $urlAug['preferred'],
            'appended_to_prompts' => $appendedToPrompts,
        ];
    }

    /**
     * When the CP site locale is German, planner-written labels/prompts must avoid ß.
     */
    private function germanNoEszettPlannerRule(?string $siteLocale): string
    {
        if ($siteLocale === null || trim($siteLocale) === '') {
            return '';
        }

        $normalized = strtolower(str_replace('_', '-', trim($siteLocale)));

        if (! str_starts_with($normalized, 'de')) {
            return '';
        }

        return "- For any German in \"label\" or \"prompt\": NEVER use ß; always use ss (e.g. Strasse, gross, heiss).\n";
    }

    /**
     * Tolerant JSON parsing — accepts {entries:[…]}, a bare array, or a single object.
     *
     * @param  array<int, array{handle: string, title: string, blueprints: array<int, array{handle: string, title: string}>}>  $catalog
     * @param  string[]  $warnings
     * @return array<int, array{collection: string, blueprint: string, prompt: string, label: string}>
     */
    private function parseAndNormalize(string $raw, array $catalog, string $originalPrompt, array &$warnings): array
    {
        $response = trim($raw);
        $response = preg_replace('/^```(?:json)?\s*/i', '', $response);
        $response = preg_replace('/\s*```\s*$/', '', $response);
        $response = trim($response);

        $candidates = [];

        $balanced = JsonObjectExtractor::firstObject($response);
        if ($balanced !== null) {
            $candidates[] = $balanced;
        }

        $firstBrace = strpos($response, '{');
        $lastBrace = strrpos($response, '}');
        if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
            $slice = substr($response, $firstBrace, $lastBrace - $firstBrace + 1);
            if ($balanced === null || $slice !== $balanced) {
                $candidates[] = $slice;
            }
        }

        $firstBracket = strpos($response, '[');
        $lastBracket = strrpos($response, ']');
        if ($firstBracket !== false && $lastBracket !== false && $lastBracket > $firstBracket) {
            $candidates[] = substr($response, $firstBracket, $lastBracket - $firstBracket + 1);
        }

        $decoded = null;
        foreach ($candidates as $jsonStr) {
            try {
                $decoded = json_decode($jsonStr, true, 512, JSON_THROW_ON_ERROR);
                break;
            } catch (\JsonException) {
                continue;
            }
        }

        if (! is_array($decoded)) {
            throw new \RuntimeException(__('Could not parse planner response.'));
        }

        $list = null;

        if (isset($decoded['entries']) && is_array($decoded['entries'])) {
            $list = $decoded['entries'];
        } elseif (array_is_list($decoded)) {
            $list = $decoded;
        } elseif (isset($decoded['collection']) || isset($decoded['blueprint'])) {
            $list = [$decoded];
        }

        if (! is_array($list) || $list === []) {
            return [];
        }

        $normalized = [];

        foreach ($list as $i => $row) {
            if (! is_array($row)) {
                continue;
            }

            $collection = isset($row['collection']) && is_string($row['collection']) ? trim($row['collection']) : '';
            $blueprint = isset($row['blueprint']) && is_string($row['blueprint']) ? trim($row['blueprint']) : '';
            $entryPrompt = isset($row['prompt']) && is_string($row['prompt']) ? trim($row['prompt']) : '';
            $label = isset($row['label']) && is_string($row['label']) ? trim($row['label']) : '';

            $validated = $this->validateAndCoerceTarget($catalog, $collection, $blueprint);

            if ($validated === null) {
                $warnings[] = __('Skipped entry #:n: invalid collection or blueprint returned by the AI.', ['n' => $i + 1]);

                continue;
            }

            if ($entryPrompt === '') {
                $entryPrompt = $originalPrompt;
            }

            if ($label === '') {
                $label = Str::limit(strip_tags($entryPrompt), 60);
            }

            $normalized[] = [
                'collection' => $validated['collection'],
                'blueprint' => $validated['blueprint'],
                'prompt' => $entryPrompt,
                'label' => $label,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<int, array{handle: string, title: string, blueprints: array<int, array{handle: string, title: string}>}>  $catalog
     * @return array{collection: string, blueprint: string}|null
     */
    private function validateAndCoerceTarget(array $catalog, string $collection, string $blueprint): ?array
    {
        if ($collection === '') {
            return null;
        }

        foreach ($catalog as $row) {
            if (($row['handle'] ?? '') !== $collection) {
                continue;
            }

            $blueprints = $row['blueprints'] ?? [];
            $bpHandles = array_map(fn ($b) => $b['handle'] ?? '', $blueprints);

            if ($blueprint !== '' && in_array($blueprint, $bpHandles, true)) {
                return ['collection' => $collection, 'blueprint' => $blueprint];
            }

            if (! empty($bpHandles)) {
                return ['collection' => $collection, 'blueprint' => $bpHandles[0]];
            }
        }

        return null;
    }

    /**
     * Fallback when the planner fails: defer to the existing single-entry resolver.
     *
     * @param  string[]  $warnings
     * @param  array{appendix: string, warnings: array<int, string>, preferred: PreferredAssetPaths}  $urlAug
     * @param  array{appendix: string, warnings: array<int, string>}  $figmaAug
     * @return array{entries: array<int, array{collection: string, blueprint: string, prompt: string, label: string}>, warnings: string[], url_augmentation: array{appendix: string, warnings: array<int, string>, preferred: PreferredAssetPaths, appended_to_prompts: bool}}
     */
    private function singleEntryFallback(string $prompt, ?string $attachmentContent, array $warnings, array $urlAug, array $figmaAug = ['appendix' => '', 'warnings' => []]): array
    {
        $resolved = $this->generator->resolveTargetFromPrompt($prompt, $attachmentContent, $urlAug, $figmaAug);

        foreach ($urlAug['warnings'] as $w) {
            $warnings[] = $w;
        }

        foreach ($figmaAug['warnings'] as $w) {
            $warnings[] = $w;
        }

        $combinedPrompt = trim($prompt.$urlAug['appendix'].$figmaAug['appendix']);

        return [
            'entries' => [[
                'collection' => $resolved['collection'],
                'blueprint' => $resolved['blueprint'],
                'prompt' => $combinedPrompt,
                'label' => Str::limit(strip_tags($prompt), 60),
            ]],
            'warnings' => $warnings,
            'url_augmentation' => $this->urlAugmentationForResponse($urlAug, true),
        ];
    }
}
