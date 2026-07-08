<?php

namespace BoldWeb\StatamicAiAssistant\Tools\Advanced;

use BoldWeb\StatamicAiAssistant\Tools\ChatTool;

/**
 * The structural tool pack, gated by the `advanced_tools` access feature
 * (AgentAccess) and the `advanced_tools` config master switch. Registered as
 * one unit so the planner wiring stays a single conditional.
 */
class AdvancedToolset
{
    /**
     * Whether the pack is available for a batch session: the requesting user's
     * grant was resolved at request time and stored on the session (the planner
     * runs in a queue with no authenticated user), and a site can hard-disable
     * the pack via config regardless of grants.
     *
     * @param  array<string, mixed>  $session
     */
    public static function enabledForSession(array $session): bool
    {
        return (bool) ($session['advanced_tools'] ?? false)
            && (bool) config('statamic-ai-assistant.advanced_tools', true);
    }

    /**
     * @return array<int, ChatTool>
     */
    public static function tools(): array
    {
        return [
            new ListBlueprintsTool,
            new ListFieldsetsTool,
            new ReadBlueprintTool,
            new CreateBlueprintTool,
            new UpdateBlueprintTool,
            new CreateCollectionTool,
            new ConfigureCollectionTool,
            new CreateTaxonomyTool,
        ];
    }

    /**
     * Names of the tools that WRITE structure. The planner counts their
     * successful calls so a structural-only run completes as a success even
     * though no entry jobs were dispatched.
     *
     * @return array<int, string>
     */
    public static function writeToolNames(): array
    {
        return ['create_blueprint', 'update_blueprint', 'create_collection', 'configure_collection', 'create_taxonomy'];
    }

    /**
     * The AVAILABLE TOOLS lines + workflow rule appended to the planner system
     * prompt when (and only when) the pack is registered.
     */
    public static function plannerPromptBlock(): string
    {
        return "- `list_blueprints` / `read_blueprint`: inspect which blueprints exist and their fields. read_blueprint also returns the raw `structure` (tabs + fieldset imports) — the site's conventions.\n"
            ."- `list_fieldsets`: list the site's reusable fieldsets (hero, seo, ...) and their fields.\n"
            ."- `create_collection`: create a new collection (then create a blueprint for it).\n"
            ."- `configure_collection`: change an existing collection's settings (title, route, dated, taxonomies, ...).\n"
            ."- `create_taxonomy`: create a new taxonomy, then attach it to a collection via configure_collection.\n"
            ."- `create_blueprint` / `update_blueprint`: create a blueprint or add/modify fields on one. Updates MERGE by default; read_blueprint first.\n"
            ."\nSTRUCTURAL CHANGES RULE: the tools above change the site's STRUCTURE and apply immediately — there is no draft or review step. "
            ."Use them ONLY when the newest user message explicitly asks for a structural change (\"create a collection\", \"add a field to X\", \"new taxonomy\"). "
            ."NEVER create or modify collections, blueprints, taxonomies or fields on your own initiative to make a content task fit — if needed structure is missing, "
            ."say what is missing in your summary instead. After creating a collection + blueprint, you may create entries in it in the same run if the user asked for that.\n"
            ."\nBLUEPRINT CONVENTIONS: before creating a blueprint, call list_fieldsets AND read_blueprint on one existing blueprint of a similar collection to learn this site's structure. "
            ."When the user names an existing shared block (a hero, SEO fields, meta, ...) or a fieldset covers it, REFERENCE it with an {\"import\": \"<fieldset_handle>\"} row — do NOT recreate its fields inline. "
            ."Define inline fields only for genuinely new, content-specific needs. A structural change that only ADDS what the user asked for is correct; a blueprint that duplicates an existing fieldset is wrong.\n";
    }
}
