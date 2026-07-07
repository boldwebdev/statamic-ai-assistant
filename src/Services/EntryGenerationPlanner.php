<?php

namespace BoldWeb\StatamicAiAssistant\Services;

use BoldWeb\StatamicAiAssistant\Jobs\GeneratePlannedEntryJob;
use BoldWeb\StatamicAiAssistant\Services\PreferredAssetPaths;
use BoldWeb\StatamicAiAssistant\Support\EntryCreationPolicy;
use BoldWeb\StatamicAiAssistant\Support\JsonObjectExtractor;
use BoldWeb\StatamicAiAssistant\Support\PlanEntryDecorator;
use BoldWeb\StatamicAiAssistant\Tools\ChatToolRunner;
use BoldWeb\StatamicAiAssistant\Tools\ListTaxonomiesTool;
use BoldWeb\StatamicAiAssistant\Tools\ReadEntryStructureTool;
use BoldWeb\StatamicAiAssistant\Tools\ReadGlobalsTool;
use BoldWeb\StatamicAiAssistant\Tools\ReadNavTreeTool;
use BoldWeb\StatamicAiAssistant\Tools\ToolContext;
use BoldWeb\StatamicAiAssistant\Tools\UrlFetchTool;
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
    /** Max consecutive empty model responses to nudge through before giving up. */
    private const MAX_EMPTY_RESPONSES = 2;

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
                ."0. Only ever fetch URLs the user explicitly provided (or same-site links found inside those fetched pages). NEVER invent, guess, or try alternative/variant URLs — a guessed URL will be refused. If the user provided NO URL, do not fetch anything: plan from the request and the CMS context alone.\n"
                ."1. When the user DID provide one or more http(s) URLs, you MUST call the **fetch_page_content** tool to retrieve them BEFORE producing JSON. Never guess how many entries to plan from a URL alone.\n"
                ."2. Use the fetched content to decide how many entries to create. If the URL is a homepage, sitemap, listing, or section index on the SAME site, fetch additional sub-pages as needed to enumerate every item the user asked about.\n"
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

        $shortlistLimit = max(0, (int) config('statamic-ai-assistant.bold_agent_catalog_entries_shortlist', 25));
        $catalog = $this->generator->getCollectionsCatalog($shortlistLimit);
        if ($catalog === []) {
            throw new \RuntimeException(__('No collections with blueprints are available.'));
        }

        $catalogJson = json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        // The session carries the effective cap resolved from the requesting CP
        // user's role (EntryCreationPolicy). Non-super users are limited to a
        // single entry; fall back to the configured cap for legacy sessions.
        $cap = $this->resolvePlanCap($session);

        // Hard single-entry mode for capped (non-super) users: make the one-entry
        // rule impossible to miss, on top of the server-side cap enforcement.
        $singleEntryRule = $cap === 1
            ? "STRICT SINGLE-ENTRY LIMIT: you may create OR update EXACTLY ONE entry in this request. "
                ."Make a single create_entry_job OR a single update_entry_job call, then stop. "
                ."If the user asked for several entries, pick the single best match, do that one, and note in your final summary that only one was created due to the current permission limit.\n\n"
            : '';

        $system = "You are a Statamic CMS planner running asynchronously. The user described one or more entries they want to create OR update. "
            ."Your job is to figure out which case applies and dispatch tool calls accordingly. **Never produce JSON output yourself** — entries are created or updated exclusively through tool calls.\n\n"
            ."This may be an ongoing conversation: earlier turns appear as prior messages. When the user says \"this entry\", \"it\", \"the same page\", etc., they mean an entry discussed in an earlier turn — the assistant turns note the entries they acted on (with entry_id for updates). Use that id to target it, or call find_entries / search_entry_content to re-resolve it by name. Only act on what the newest user message asks for.\n\n"
            .$singleEntryRule
            ."AVAILABLE TOOLS:\n"
            ."- `fetch_page_content`: read external http(s) URLs — but ONLY URLs the user explicitly provided (or same-site links inside those pages). Never invent, guess, or try alternative/variant URLs; if the user gave no URL, do not fetch anything.\n"
            ."- `create_entry_job`: queue ONE new entry for asynchronous creation.\n"
            ."- `update_entry_job`: queue an UPDATE to an existing entry (you must know its `entry_id`).\n"
            ."- `find_entries`: search existing entries by title/slug when the catalog shortlist below is not enough.\n"
            ."- `search_entry_content`: find an entry by a phrase/name/value that appears INSIDE its body content (not its title). Use this when the user quotes text from within a page rather than its title.\n"
            ."- `answer_question`: reply to a read-only question without creating or updating anything.\n"
            ."- `read_entry_structure`: read an existing entry's layout/components (its sets in order) so a new or updated entry can mirror them. Pass `entry_id` (from the catalog or find_entries) or a title/slug `query`. The reference entry may use a different blueprint — mirror the structure, but each per-entry `prompt` you write must instruct mapping onto the target blueprint's own sets, never copying set handles blindly.\n"
            ."- `read_globals`: read site-wide global values (general settings, contact details, social links, default CTA links). Call with no args for all sets, or a `handle` for one. Use it to reuse real contact info / CTA links instead of inventing them.\n"
            ."- `read_nav_tree`: read a navigation as a hierarchy of page titles (with URLs + linked entry ids). Call with no args to list navigations, or a `handle` for its tree. Use it to understand site structure or pick internal link targets.\n"
            ."- `list_taxonomies`: list taxonomies, or the terms of one (pass a `taxonomy` handle). Use the returned slugs when a per-entry prompt must set a `terms` field, so you only reference terms that exist.\n\n"
            ."WORKFLOW:\n"
            ."0. First decide whether the user is REQUESTING A CHANGE (create/update) or just ASKING A QUESTION. If they are only asking a read-only question (\"which entry contains X?\", \"do we have a page about Y?\", \"what is the id of Z?\", or a superlative like \"which is the biggest / longest / newest entry in Events?\"), do NOT create or update anything and do NOT ask the user for a title — a question never needs one. Gather what you need with find_entries (optionally scoped to a collection handle) and/or search_entry_content, then call **answer_question** with a concise answer. That ends the run successfully. Follow-up messages in an ongoing conversation are frequently such questions.\n"
            ."1. Otherwise, decide whether the user wants to create new entries, update existing entries, or both. Phrases like \"add\", \"new\", \"create\", \"write a post about\" → create. Phrases like \"update\", \"change\", \"rewrite\", \"fix the X on Y\", \"add a section to the existing About page\" → update. When the user refers to content by a definite name (\"the About page\", \"our rooms page\", \"unsere Zimmer-Seite\") the entry most likely EXISTS — treat that as update intent unless they explicitly ask for a new entry.\n"
            ."2. For UPDATES: find the target entry's `entry_id`. A title alone is ENOUGH to identify an entry — never claim the target is ambiguous or unknown just because the user only gave a title. The catalog below carries an `entries` shortlist (recently updated) and a `count` per collection. First scan the shortlist and match the named entry by title, ignoring case, punctuation and connective words (so \"Body Soul\", \"Body and Soul\" and \"Body & Soul\" all refer to the same entry); if you find it, use its id directly. If it is not in the shortlist, you MUST call **find_entries** with the title (and optionally a collection handle) before concluding anything. If the user identifies the entry by a phrase or value from its body/content rather than a title, call **search_entry_content** instead. Only after searching returns no match may you give up. If the intent is clearly UPDATE but neither the shortlist nor find_entries locates the entry, do NOT fall back to creating a new entry — end with `Cannot proceed:` naming the entry you could not find.\n"
            ."2b. AVOID DUPLICATES on creates: before dispatching create_entry_job for content that may already exist (a topic matching an entry title in the shortlist, or a fetched URL whose title matches an existing entry), check the shortlist or call find_entries. If a matching entry exists and the user's wording is compatible with updating it, prefer update_entry_job over creating a near-duplicate.\n"
            ."3. For CREATES with URLs in the user message, call **fetch_page_content** first to inspect them. For listing/index URLs on the same site, enumerate every relevant detail page. Do NOT fetch URLs the user did not provide — never guess or try URLs that might exist.\n"
            ."4. Dispatch each entry as soon as you have enough info — do not wait to plan all entries before dispatching. Each tool call enqueues a worker that starts immediately, in parallel.\n"
            ."5. Use one tool call per distinct entry. Never collapse many entries into a single call. The combined cap is {$cap} create_entry_job + update_entry_job calls per request.\n"
            ."6. For CREATES: pick collection + blueprint **only** from the catalog below (handles must match exactly, case-sensitive). The chosen blueprint must be one listed for that collection. If unsure, prefer the collection whose handle is `pages` if present; otherwise the first catalog collection.\n"
            ."7. Each `prompt` MUST be a complete, self-contained brief in the user's language: include the URL (when applicable), the topic, and any constraints. For updates, describe ONLY what should change — do not restate the rest of the entry. Do not reference other entries.\n"
            ."8. The `label` is a short human title (2-6 words) for the UI in the user's language.\n"
            .$this->germanNoEszettPlannerRule($locale)
            ."9. When you have dispatched every entry the user asked for (or hit the cap), end your turn with a short plain-text summary like `Done — N actions dispatched.` (no JSON, no tool calls).\n"
            ."10. If the request is impossible, end your turn with a short plain-text explanation starting with `Cannot proceed:`. But NEVER give up on an update target without first checking the shortlist AND calling find_entries — a bare title is not a reason to stop.";

        $attachmentPart = $attachment
            ? "\n\nAdditional context from an attached document (excerpt):\n".Str::limit($attachment, 6000)
            : '';

        // Build the message array from the conversation transcript so follow-up
        // turns carry context. Only the newest user turn carries the catalog +
        // attachment. On turn 1 the transcript is exactly [user], so this yields
        // the same [system, user] shape as a single-shot request (regression-tested).
        $transcript = is_array($session['transcript'] ?? null) && $session['transcript'] !== []
            ? $session['transcript']
            : [['role' => 'user', 'text' => $prompt, 'entry_ids' => [], 'kind' => null]];

        $lastUserIdx = null;
        foreach ($transcript as $i => $turn) {
            if (($turn['role'] ?? '') === 'user') {
                $lastUserIdx = $i;
            }
        }

        $messages = [['role' => 'system', 'content' => $system]];
        foreach ($transcript as $i => $turn) {
            $role = ($turn['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $text = (string) ($turn['text'] ?? '');
            if ($role === 'assistant') {
                $text .= $this->renderEntryRefs(
                    is_array($turn['entry_ids'] ?? null) ? $turn['entry_ids'] : [],
                    $session,
                );
            }
            if ($i === $lastUserIdx) {
                $text = "Available collections and blueprints (JSON):\n{$catalogJson}\n\nUser request:\n{$text}{$attachmentPart}";
            }
            if (trim($text) === '') {
                continue; // never send an empty message (some providers reject them)
            }
            $messages[] = ['role' => $role, 'content' => $text];
        }

        // Absolute cap for addPlannedEntry: prior turns' entries accumulate in the
        // session, so a follow-up gets its own per-turn allowance on top of them.
        $priorEntryCount = is_array($session['entry_order'] ?? null) ? count($session['entry_order']) : 0;

        $heartbeat = function () use ($sessionId): void {
            // Cancellation poll between LLM rounds + tool calls; throwing exits the loop.
            if ($this->batch !== null && $this->batch->isCancelled($sessionId)) {
                throw new \RuntimeException((string) __('Cancelled.'));
            }
        };

        $toolWarnings = [];
        $createdRowIds = [];
        $this->planAgenticToolLoop(
            $messages,
            $prompt,
            $sessionId,
            $catalog,
            $cap,
            $toolWarnings,
            $heartbeat,
            $priorEntryCount,
            $createdRowIds,
        );

        foreach ($toolWarnings as $w) {
            if (is_string($w) && $w !== '') {
                $this->batch->appendPlannerWarning($sessionId, $w);
            }
        }

        // Record this turn's outcome in the transcript so the next turn has context.
        // The answer_question path already recorded its own assistant turn in the loop.
        if ($createdRowIds !== []) {
            $this->batch->appendAssistantTurn(
                $sessionId,
                $this->summarizeCreatedEntries($createdRowIds, $sessionId),
                $createdRowIds,
                'summary',
            );
        }

        $this->batch->markPlanningComplete($sessionId);
    }

    /**
     * A short human-readable suffix naming the entries an assistant turn acted on,
     * so a later "add a section to this entry" can resolve the target.
     *
     * @param  array<int, string>  $entryRowIds  Session plan-row ids from a prior turn.
     * @param  array<string, mixed>  $session
     */
    private function renderEntryRefs(array $entryRowIds, array $session): string
    {
        $entries = is_array($session['entries'] ?? null) ? $session['entries'] : [];
        $parts = [];
        foreach ($entryRowIds as $rid) {
            $row = $entries[(string) $rid] ?? null;
            if (! is_array($row)) {
                continue;
            }
            $label = (string) ($row['label'] ?? '');
            $op = (string) ($row['operation'] ?? 'create');
            $statamicId = isset($row['entry_id']) && is_string($row['entry_id']) ? $row['entry_id'] : '';
            $desc = $label !== '' ? "“{$label}”" : (string) ($row['collection_title'] ?? ($row['collection'] ?? 'entry'));
            if ($op === 'update' && $statamicId !== '') {
                $desc .= " (entry_id {$statamicId})";
            }
            $desc .= " [{$op}]";
            $parts[] = $desc;
        }

        return $parts === [] ? '' : "\n(Entries this turn: ".implode('; ', $parts).')';
    }

    /**
     * Deterministic summary of the entries dispatched this turn (ids/labels are
     * guaranteed correct, unlike the model's free-text tail).
     *
     * @param  array<int, string>  $createdRowIds
     */
    private function summarizeCreatedEntries(array $createdRowIds, string $sessionId): string
    {
        $session = $this->batch?->getSession($sessionId);
        $entries = is_array($session['entries'] ?? null) ? $session['entries'] : [];

        $created = [];
        $updated = [];
        foreach ($createdRowIds as $rid) {
            $row = $entries[(string) $rid] ?? null;
            if (! is_array($row)) {
                continue;
            }
            $label = (string) ($row['label'] ?? '');
            $name = $label !== '' ? "“{$label}”" : (string) ($row['collection_title'] ?? ($row['collection'] ?? 'entry'));
            if ((string) ($row['operation'] ?? 'create') === 'update') {
                $updated[] = $name;
            } else {
                $created[] = $name;
            }
        }

        $clauses = [];
        if ($created !== []) {
            $clauses[] = (string) __('created :names', ['names' => implode(', ', $created)]);
        }
        if ($updated !== []) {
            $clauses[] = (string) __('updated :names', ['names' => implode(', ', $updated)]);
        }

        if ($clauses === []) {
            $n = count($createdRowIds);

            return (string) __('Done — :n actions dispatched.', ['n' => $n]);
        }

        // "Done — created “A”, “B” and updated “C”."
        return (string) __('Done — :summary.', ['summary' => implode(' '.__('and').' ', $clauses)]);
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
        $restrictFetch = (bool) config('statamic-ai-assistant.entry_generator_restrict_fetch_to_prompt_urls', true);
        $allowedFetchHosts = UrlFetchTool::hostsFromMessages($this->promptUrlFetcher, $messages);

        $runner = new ChatToolRunner([
            new UrlFetchTool($this->promptUrlFetcher, $allowedFetchHosts, $restrictFetch),
            new ReadEntryStructureTool(
                new EntryStructureSerializer,
                fn (?string $collection, string $query, int $limit) => $this->generator->findEntriesShortlist($collection, $query, $limit),
            ),
            new ReadGlobalsTool,
            new ReadNavTreeTool,
            new ListTaxonomiesTool,
        ], new ToolContext(
            warningSink: function (string $w) use (&$toolWarningsOut) {
                $toolWarningsOut[] = $w;
            },
            heartbeat: $streamHeartbeat,
        ));

        $maxRounds = (int) config('statamic-ai-assistant.entry_generator_tool_max_rounds', 120);
        // Planner JSON can be huge (many URLs + briefs); keep separate from per-entry generator_max_tokens.
        $maxTokens = max(4096, (int) config('statamic-ai-assistant.entry_generator_planner_max_output_tokens', 12000));

        $promptHasUrl = (bool) preg_match('~\bhttps?://[^\s<>\]\}\)\"\'`]+~iu', $userPrompt);
        $working = $messages;

        for ($round = 0; $round < $maxRounds; $round++) {
            $streamHeartbeat?->__invoke();

            $forceTool = $promptHasUrl && $runner->callCount('fetch_page_content') === 0;
            $toolChoice = $forceTool
                ? ['type' => 'function', 'function' => ['name' => 'fetch_page_content']]
                : 'auto';

            $data = $this->aiService->createChatCompletion($working, $maxTokens, $runner->definitions(), $toolChoice, $streamHeartbeat);
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

                $runner->consume($toolCalls, $working);

                continue;
            }

            $content = $msg['content'] ?? '';
            if (is_string($content) && trim($content) !== '') {
                Log::info('[entry-gen-tool] planner done', [
                    'rounds' => $round + 1,
                    'fetches' => $runner->callCount('fetch_page_content'),
                ]);

                return $content;
            }

            throw new \RuntimeException(__('Planner returned no usable text after tool use.'));
        }

        Log::error('[entry-gen-tool] planner tool loop exceeded max rounds', [
            'max_rounds' => $maxRounds,
            'fetches' => $runner->callCount('fetch_page_content'),
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
        int $priorEntryCount = 0,
        array &$createdRowIdsOut = [],
    ): void {
        // Shared runner for the stateless read tools (URL fetch + entry-structure
        // read). The stateful job tools (create/update/find) stay outside the
        // runner and are handled via the $fallback in consume() below, since they
        // mutate session/cap state that a generic runner shouldn't own.
        $toolContext = new ToolContext(
            warningSink: function (string $w) use (&$toolWarningsOut) {
                $toolWarningsOut[] = $w;
            },
            heartbeat: $streamHeartbeat,
            activitySink: fn (string $line) => $this->batch?->appendPlannerActivity($sessionId, $line),
        );
        $restrictFetch = (bool) config('statamic-ai-assistant.entry_generator_restrict_fetch_to_prompt_urls', true);
        $allowedFetchHosts = UrlFetchTool::hostsFromMessages($this->promptUrlFetcher, $messages);

        $runner = new ChatToolRunner([
            new UrlFetchTool($this->promptUrlFetcher, $allowedFetchHosts, $restrictFetch),
            new ReadEntryStructureTool(
                new EntryStructureSerializer,
                fn (?string $collection, string $query, int $limit) => $this->generator->findEntriesShortlist($collection, $query, $limit),
            ),
            new ReadGlobalsTool,
            new ReadNavTreeTool,
            new ListTaxonomiesTool,
        ], $toolContext);

        // Seed the activity feed so the CP never shows an empty "0 entries" panel
        // while the planner is thinking before its first tool call.
        $this->batch?->appendPlannerActivity($sessionId, (string) __('Analyzing your request…'));

        $tools = array_merge($runner->definitions(), [
            $this->createEntryJobToolDefinition($cap),
            // The two tools below power the BOLD agent's UPDATE flow. They are
            // intentionally separate from create_entry_job — to drop update
            // support, remove these two tool defs + their handlers + the
            // dispatch branches below; create_entry_job is unaffected.
            $this->updateEntryJobToolDefinition($cap),
            $this->findEntriesToolDefinition(),
            $this->searchEntryContentToolDefinition(),
            $this->answerQuestionToolDefinition(),
        ]);
        $maxRounds = (int) config('statamic-ai-assistant.entry_generator_tool_max_rounds', 120);
        $maxTokens = max(4096, (int) config('statamic-ai-assistant.entry_generator_planner_max_output_tokens', 12000));
        $created = 0;
        // Counts every entry-locating search (find_entries + search_entry_content);
        // used to decide whether a give-up happened without any search attempt.
        $searchCalls = 0;
        // One-shot guard for the "gave up without searching" self-correction below.
        $nudgedEmptyResult = false;
        // Bounds recovery from empty model responses (no content + no tool calls).
        $emptyResponses = 0;
        // Set when the model calls answer_question — a read-only answer, not a failure.
        $plannerAnswer = null;

        $promptHasUrl = (bool) preg_match('~\bhttps?://[^\s<>\]\}\)\"\'`]+~iu', $userPrompt);
        // Some providers/models don't honour a forced specific-tool choice and
        // return an empty turn. Once we see the forced fetch ignored, stop forcing
        // and let the model proceed with tool_choice=auto (it can still fetch on
        // its own) — otherwise it can get stuck emitting empty responses.
        $forceToolDisabled = false;
        $working = $messages;

        for ($round = 0; $round < $maxRounds; $round++) {
            $streamHeartbeat?->__invoke();

            $forceTool = ! $forceToolDisabled && $promptHasUrl && $runner->callCount('fetch_page_content') === 0 && $created === 0;
            $toolChoice = $forceTool
                ? ['type' => 'function', 'function' => ['name' => 'fetch_page_content']]
                : 'auto';

            $data = $this->aiService->createChatCompletion($working, $maxTokens, $tools, $toolChoice, $streamHeartbeat);
            $choice = $data['choices'][0] ?? null;
            $msg = is_array($choice) ? ($choice['message'] ?? null) : null;

            if (! is_array($msg)) {
                throw new \RuntimeException(__('Unexpected planner response shape.'));
            }

            $msg = $this->normalizeAssistantMessage($msg);

            $toolCalls = $msg['tool_calls'] ?? null;
            $hasToolCalls = is_array($toolCalls) && $toolCalls !== [];

            if ($forceTool && ! $hasToolCalls) {
                // The model ignored the forced tool — stop forcing so subsequent
                // rounds use tool_choice=auto and it can move forward.
                $forceToolDisabled = true;

                Log::warning('[entry-gen-tool] agentic planner forced tool call IGNORED; relaxing to auto', [
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

                // Read tools (fetch_page_content, read_entry_structure) go through
                // the shared runner. The stateful job tools are handled in the
                // fallback so their session/cap mutations stay in the planner.
                // Absolute cap for addPlannedEntry (prior turns' entries + this
                // turn's allowance); $cap stays the per-turn model limit.
                $addCap = $priorEntryCount + $cap;

                $runner->consume($toolCalls, $working, function (string $name, string $args) use ($sessionId, $catalog, $cap, $addCap, &$created, &$searchCalls, &$plannerAnswer, &$createdRowIdsOut) {
                    if ($name === 'answer_question') {
                        try {
                            $decoded = json_decode($args, true, 512, JSON_THROW_ON_ERROR);
                        } catch (\JsonException) {
                            return ['ok' => false, 'error' => 'invalid_arguments_json'];
                        }
                        $answer = is_array($decoded) && isset($decoded['answer']) && is_string($decoded['answer'])
                            ? trim($decoded['answer'])
                            : '';
                        if ($answer === '') {
                            return ['ok' => false, 'error' => 'missing_answer'];
                        }
                        $plannerAnswer = $answer;

                        return ['ok' => true];
                    }

                    if ($name === 'create_entry_job') {
                        $result = $this->handleCreateEntryJobToolCall($args, $sessionId, $catalog, $cap, $created, $addCap);
                        if (($result['ok'] ?? false) === true) {
                            $created++;
                            if (isset($result['id']) && is_string($result['id'])) {
                                $createdRowIdsOut[] = $result['id'];
                            }
                        }

                        return $result;
                    }

                    // BOLD agent UPDATE tool — independent of create_entry_job.
                    if ($name === 'update_entry_job') {
                        $result = $this->handleUpdateEntryJobToolCall($args, $sessionId, $catalog, $cap, $created, $addCap);
                        if (($result['ok'] ?? false) === true) {
                            $created++;
                            if (isset($result['id']) && is_string($result['id'])) {
                                $createdRowIdsOut[] = $result['id'];
                            }
                        }

                        return $result;
                    }

                    if ($name === 'find_entries') {
                        $searchCalls++;

                        return $this->handleFindEntriesToolCall($args);
                    }

                    if ($name === 'search_entry_content') {
                        $searchCalls++;

                        return $this->handleSearchEntryContentToolCall($args);
                    }

                    return null;
                });

                // answer_question is terminal: the model answered a read-only
                // question. Record it as a successful result (not a failure) and stop.
                if ($plannerAnswer !== null) {
                    $this->completePlannerAnswer($sessionId, $plannerAnswer, $round, $created);

                    return;
                }

                continue;
            }

            $content = $msg['content'] ?? '';
            if (is_string($content) && trim($content) !== '') {
                // Some models emit answer_question as plain assistant text instead of
                // a structured tool_call (e.g. answer_question:{"answer":"…"}). Treat
                // that as a successful read-only answer, not a planning failure.
                $plainAnswer = $this->extractAnswerQuestion($content);
                if ($plainAnswer !== null) {
                    $this->completePlannerAnswer($sessionId, $plainAnswer, $round, $created);

                    return;
                }

                // Self-correction: mid-tier models sometimes bail with "Cannot
                // proceed / I can't determine which entry" WITHOUT ever calling a
                // search tool — even for a read-only question they could just answer,
                // or when the entry sits in the catalog shortlist. If nothing was
                // dispatched and no search was attempted, nudge the model to either
                // answer the question or resolve the target before accepting the
                // give-up. Fires at most once, so a truly-impossible request still ends.
                if ($created === 0 && $searchCalls === 0 && ! $nudgedEmptyResult) {
                    $nudgedEmptyResult = true;

                    Log::info('[entry-gen-tool] agentic planner bailed without searching; nudging retry', [
                        'round' => $round + 1,
                    ]);

                    $working[] = [
                        'role' => 'assistant',
                        'content' => $content,
                    ];
                    $working[] = [
                        'role' => 'user',
                        'content' => 'Do not give up yet — you have not used any tools. First re-read the newest user message and decide the intent:'."\n"
                            .'• IF IT IS A READ-ONLY QUESTION (e.g. "which is the biggest/longest entry?", "which entry contains X?", "do we have a page about Y?", "what is the id of Z?"): this is NOT a create/update request and needs no title from the user. Use find_entries (optionally scoped to a collection handle) and/or search_entry_content to gather what you need, then call answer_question with a concise answer. Never reply "Cannot proceed:" for a question you can look up.'."\n"
                            .'• IF IT IS A CHANGE REQUEST (create/update): a title alone is enough to identify an entry — do NOT claim the target is ambiguous or unknown before searching. Check the catalog shortlist above, matching by title while ignoring case, punctuation and connective words (so "Body Soul", "Body and Soul" and "Body & Soul" are the same). If it is there, call update_entry_job with its id; if not, call find_entries with the title first.'."\n"
                            .'Only reply "Cannot proceed:" if, after actually using the tools, the request genuinely cannot be fulfilled.',
                    ];

                    continue;
                }

                Log::info('[entry-gen-tool] agentic planner done', [
                    'rounds' => $round + 1,
                    'fetches' => $runner->callCount('fetch_page_content'),
                    'entries_created' => $created,
                ]);

                if ($created === 0) {
                    throw new \RuntimeException(trim($content) !== ''
                        ? trim($content)
                        : (string) __('Planner ended without creating any entries.'));
                }

                return;
            }

            // Empty response: the model returned neither content nor a tool call
            // (a provider hiccup, an ignored forced tool, or an empty completion
            // after a fetch). Recover generically rather than hard-failing:
            //  - if entries were already dispatched, finish successfully;
            //  - otherwise nudge the model to act, bounded so we can't loop forever.
            if ($created > 0) {
                Log::info('[entry-gen-tool] agentic planner returned empty after dispatching; finishing', [
                    'rounds' => $round + 1,
                    'entries_created' => $created,
                ]);

                return;
            }

            $emptyResponses++;
            if ($emptyResponses <= self::MAX_EMPTY_RESPONSES) {
                // Capture why the completion was empty (e.g. finish_reason "length"
                // = the model hit the output-token cap) so the cause is diagnosable.
                Log::warning('[entry-gen-tool] agentic planner returned an empty response; nudging retry', [
                    'round' => $round + 1,
                    'attempt' => $emptyResponses,
                    'finish_reason' => is_array($choice) ? ($choice['finish_reason'] ?? null) : null,
                    'usage' => is_array($data) ? ($data['usage'] ?? null) : null,
                ]);

                $working[] = [
                    'role' => 'user',
                    'content' => 'Your last response was empty. Continue the task: either call a tool now '
                        .'(fetch_page_content to read a URL, find_entries / search_entry_content to locate an entry, '
                        .'create_entry_job / update_entry_job to act, or answer_question for a read-only question), '
                        .'or, if the request truly cannot be fulfilled, reply with a short message starting with "Cannot proceed:".',
                ];

                continue;
            }

            throw new \RuntimeException(__('The AI model returned an empty response. Please try again.'));
        }

        Log::error('[entry-gen-tool] agentic planner tool loop exceeded max rounds', [
            'max_rounds' => $maxRounds,
            'fetches' => $runner->callCount('fetch_page_content'),
            'entries_created' => $created,
        ]);

        if ($created === 0) {
            throw new \RuntimeException(__('Planner stopped: too many tool rounds.'));
        }

        // Some entries were dispatched — surface a warning instead of failing the whole batch.
        $toolWarningsOut[] = __('Planner stopped after :n entries (too many tool rounds). Re-run if you need more.', ['n' => $created]);
    }

    /**
     * Effective per-request cap for this planning session. Prefers the value the
     * controller resolved from the CP user's role (persisted on the session);
     * falls back to the configured ceiling for older sessions without it.
     *
     * @param  array<string, mixed>  $session
     */
    private function resolvePlanCap(array $session): int
    {
        $configured = EntryCreationPolicy::configuredMaxPlanEntries();

        $sessionCap = $session['max_plan_entries'] ?? null;
        if (is_int($sessionCap) || (is_string($sessionCap) && is_numeric($sessionCap))) {
            return max(1, min($configured, (int) $sessionCap));
        }

        return $configured;
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
    private function handleCreateEntryJobToolCall(string $argumentsJson, string $sessionId, array $catalog, int $cap, int $alreadyCreated, ?int $addCap = null): array
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

        $added = $this->batch->addPlannedEntry($sessionId, $decorated, $addCap ?? $cap);
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

    // ---------------------------------------------------------------------
    //  BOLD agent UPDATE tools — kept structurally separate from
    //  create_entry_job so removing/replacing update support is a clean delete:
    //  drop these three methods + the wiring in planAgenticToolLoop.
    // ---------------------------------------------------------------------

    /**
     * @return array{type: string, function: array<string, mixed>}
     */
    private function updateEntryJobToolDefinition(int $cap): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'update_entry_job',
                'description' => 'Queue an UPDATE to one existing entry. Use when the user wants to change, rewrite, fix, or extend an entry that already exists. You must provide the entry_id (from the catalog shortlist or from find_entries). The collection and blueprint are inferred from the entry. Counts toward the same '.$cap.'-call cap as create_entry_job.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'entry_id' => [
                            'type' => 'string',
                            'description' => 'Statamic entry id of the existing entry to update.',
                        ],
                        'label' => [
                            'type' => 'string',
                            'description' => 'Short 2-6 word title for the UI card, in the user\'s language. Usually the existing entry\'s title or a hint of what is changing.',
                        ],
                        'prompt' => [
                            'type' => 'string',
                            'description' => 'Self-contained brief describing ONLY what should change on this entry, in the user\'s language. Do not restate parts of the entry the user did not ask to modify.',
                        ],
                    ],
                    'required' => ['entry_id', 'prompt'],
                ],
            ],
        ];
    }

    /**
     * @return array{type: string, function: array<string, mixed>}
     */
    private function findEntriesToolDefinition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'find_entries',
                'description' => 'Search existing entries by title or slug. Use when the catalog shortlist does not contain the entry the user is referring to. Matching is tolerant: case-insensitive, word-order independent, and connective words/symbols are ignored (so "Body Soul", "Body and Soul" and "Body & Soul" all match the same entry). Returns up to `limit` rows, best matches first, with id/title/slug/collection.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Words from the entry title or slug. Just pass the title as the user said it; punctuation, casing and word order do not need to match. Required.',
                        ],
                        'collection' => [
                            'type' => 'string',
                            'description' => 'Optional collection handle to scope the search. Omit to search all collections.',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Max rows to return (default 10, max 50).',
                        ],
                    ],
                    'required' => ['query'],
                ],
            ],
        ];
    }

    /**
     * @return array{type: string, function: array<string, mixed>}
     */
    private function searchEntryContentToolDefinition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'search_entry_content',
                'description' => 'Search INSIDE the body/content of existing entries — not just their title/slug. Use this when the user references an entry by a phrase, sentence, name or value that appears in its content (e.g. "the entry that contains \'Kursleitung: Claudia Eva Reinig\'"). Matching is tolerant (case, punctuation and word order do not matter) and works even when the phrase is split across rich-text blocks. Returns up to `limit` rows with id/title/slug/collection and a short `snippet` showing the match. The entries\' full content is scanned on the server and is NOT returned — only snippets.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'The words/phrase to look for inside entry content. Required.',
                        ],
                        'collection' => [
                            'type' => 'string',
                            'description' => 'Optional collection handle to scope (and speed up) the search. Omit to search all collections.',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Max rows to return (default 10, max 50).',
                        ],
                    ],
                    'required' => ['query'],
                ],
            ],
        ];
    }

    /**
     * Normalize legacy OpenAI `function_call` payloads into `tool_calls`.
     *
     * @param  array<string, mixed>  $msg
     * @return array<string, mixed>
     */
    private function normalizeAssistantMessage(array $msg): array
    {
        $toolCalls = $msg['tool_calls'] ?? null;
        if (is_array($toolCalls) && $toolCalls !== []) {
            return $msg;
        }

        $fn = $msg['function_call'] ?? null;
        if (! is_array($fn)) {
            return $msg;
        }

        $name = isset($fn['name']) && is_string($fn['name']) ? $fn['name'] : '';
        if ($name === '') {
            return $msg;
        }

        $args = isset($fn['arguments']) && is_string($fn['arguments']) ? $fn['arguments'] : '{}';
        $msg['tool_calls'] = [[
            'id' => 'legacy_'.Str::uuid()->toString(),
            'type' => 'function',
            'function' => ['name' => $name, 'arguments' => $args],
        ]];

        return $msg;
    }

    /**
     * Parse models that print answer_question as assistant text instead of calling the tool.
     */
    private function extractAnswerQuestion(string $content): ?string
    {
        $content = trim($content);
        if ($content === '') {
            return null;
        }

        $json = null;
        if (preg_match('/^answer_question\s*[:(]\s*(\{.*\})\s*\)?$/s', $content, $matches)) {
            $json = $matches[1];
        } elseif (preg_match('/^\{\s*"answer"\s*:/s', $content)) {
            $json = $content;
        }

        if ($json === null) {
            return null;
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (! is_array($decoded) || ! isset($decoded['answer']) || ! is_string($decoded['answer'])) {
            return null;
        }

        $answer = trim($decoded['answer']);

        return $answer !== '' ? $answer : null;
    }

    private function completePlannerAnswer(string $sessionId, string $answer, int $round, int $created): void
    {
        Log::info('[entry-gen-tool] agentic planner answered a question', [
            'rounds' => $round + 1,
            'entries_created' => $created,
        ]);
        $this->batch?->recordPlannerAnswer($sessionId, $answer);
        $this->batch?->appendAssistantTurn($sessionId, $answer, [], 'answer');
    }

    /**
     * @return array{type: string, function: array<string, mixed>}
     */
    private function answerQuestionToolDefinition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'answer_question',
                'description' => 'Answer the user directly WITHOUT creating or updating anything. Use this only when the user is asking a read-only question (e.g. "which entry contains X?", "do we already have a page about Y?", "what is the id of Z?") rather than requesting a change. First look the answer up with find_entries / search_entry_content, then call this with a short, complete answer in the user\'s language. Calling this ends the run successfully.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'answer' => [
                            'type' => 'string',
                            'description' => 'The complete answer to show the user, in their language. Include the entry title and id when relevant.',
                        ],
                    ],
                    'required' => ['answer'],
                ],
            ],
        ];
    }

    /**
     * @return array{ok: bool, results?: array<int, array{id: string, title: string, slug: string, collection: string, snippet: string}>, error?: string}
     */
    private function handleSearchEntryContentToolCall(string $argumentsJson): array
    {
        try {
            $args = json_decode($argumentsJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['ok' => false, 'error' => 'invalid_arguments_json'];
        }

        if (! is_array($args)) {
            return ['ok' => false, 'error' => 'invalid_arguments_shape'];
        }

        $query = isset($args['query']) && is_string($args['query']) ? trim($args['query']) : '';
        if ($query === '') {
            return ['ok' => false, 'error' => 'missing_query'];
        }

        $collection = isset($args['collection']) && is_string($args['collection']) ? trim($args['collection']) : null;
        if ($collection === '') {
            $collection = null;
        }

        $limit = isset($args['limit']) && is_numeric($args['limit']) ? (int) $args['limit'] : 10;

        return ['ok' => true, 'results' => $this->generator->searchEntryContent($collection, $query, $limit)];
    }

    /**
     * @param  array<int, array{handle: string, title: string, blueprints: array<int, array{handle: string, title: string}>}>  $catalog
     * @return array{ok: bool, id?: string, count?: int, error?: string, entry_id?: string, collection?: string}
     */
    private function handleUpdateEntryJobToolCall(string $argumentsJson, string $sessionId, array $catalog, int $cap, int $alreadyCreated, ?int $addCap = null): array
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
            Log::warning('[entry-gen-tool] update_entry_job invalid args', ['error' => $e->getMessage()]);

            return ['ok' => false, 'error' => 'invalid_arguments_json'];
        }

        if (! is_array($args)) {
            return ['ok' => false, 'error' => 'invalid_arguments_shape'];
        }

        $entryId = isset($args['entry_id']) && is_string($args['entry_id']) ? trim($args['entry_id']) : '';
        $entryPrompt = isset($args['prompt']) && is_string($args['prompt']) ? trim($args['prompt']) : '';
        $label = isset($args['label']) && is_string($args['label']) ? trim($args['label']) : '';

        if ($entryId === '' || $entryPrompt === '') {
            return ['ok' => false, 'error' => 'missing_required_fields'];
        }

        $entry = \Statamic\Facades\Entry::find($entryId);
        if (! $entry) {
            return ['ok' => false, 'error' => 'entry_not_found', 'entry_id' => $entryId];
        }

        $collectionHandle = (string) $entry->collectionHandle();
        $blueprintHandle = (string) ($entry->blueprint()?->handle() ?? '');

        // Sanity-check that the entry's collection still exists in the catalog
        // we showed the LLM. Blueprint we trust from the entry itself.
        $catalogHandles = array_map(fn ($r) => $r['handle'] ?? '', $catalog);
        if (! in_array($collectionHandle, $catalogHandles, true)) {
            return ['ok' => false, 'error' => 'entry_collection_not_in_catalog', 'collection' => $collectionHandle];
        }

        if ($label === '') {
            $label = (string) ($entry->value('title') ?? Str::limit(strip_tags($entryPrompt), 60));
        }

        $decorated = $this->decorator->decorateOne([
            'collection' => $collectionHandle,
            'blueprint' => $blueprintHandle,
            'prompt' => $entryPrompt,
            'label' => $label,
            'entry_id' => $entryId,
        ]);

        $added = $this->batch->addPlannedEntry($sessionId, $decorated, $addCap ?? $cap);
        if (! $added) {
            return ['ok' => false, 'error' => 'cap_or_session_rejected', 'count' => $alreadyCreated];
        }

        GeneratePlannedEntryJob::dispatch($sessionId, (string) $decorated['id']);

        return [
            'ok' => true,
            'id' => (string) $decorated['id'],
            'entry_id' => $entryId,
            'collection' => $collectionHandle,
            'count' => $alreadyCreated + 1,
        ];
    }

    /**
     * @return array{ok: bool, results?: array<int, array{id: string, title: string, slug: string, collection: string}>, error?: string}
     */
    private function handleFindEntriesToolCall(string $argumentsJson): array
    {
        try {
            $args = json_decode($argumentsJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['ok' => false, 'error' => 'invalid_arguments_json'];
        }

        if (! is_array($args)) {
            return ['ok' => false, 'error' => 'invalid_arguments_shape'];
        }

        $query = isset($args['query']) && is_string($args['query']) ? trim($args['query']) : '';
        if ($query === '') {
            return ['ok' => false, 'error' => 'missing_query'];
        }

        $collection = isset($args['collection']) && is_string($args['collection']) ? trim($args['collection']) : null;
        if ($collection === '') {
            $collection = null;
        }

        $limit = isset($args['limit']) && is_numeric($args['limit']) ? (int) $args['limit'] : 10;

        $results = $this->generator->findEntriesShortlist($collection, $query, $limit);

        return ['ok' => true, 'results' => $results];
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
