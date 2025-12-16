<?php

namespace BoldWeb\StatamicAiAssistant\Actions;

use BoldWeb\StatamicAiAssistant\Services\GroqService;
use BoldWeb\StatamicAiAssistant\Services\InfomaniakService;
use Statamic\Actions\Action;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Site;
use Symfony\Component\Yaml\Yaml;

use Illuminate\Support\Facades\Log;

class TranslateEntry extends Action
{

    public static function title()
    {
        return __('Translate');
    }

    protected static $separator = true;

    public function visibleTo($item)
    {
        // Check if translations are enabled in config, if not we don't show the action
        if (!config('statamic-ai-assistant.translations', true)) {
            return false;
        }
        // otherwise we show the action if the item is an entry and the site is multisite
        return $item instanceof Entry && $this->isMultisite();
    }

    public function authorize($user, $item)
    {
        return $user->can('edit', $item);
    }

    protected function fieldItems($items = [])
    {
        if (!$this->isMultisite()) {
            return [];
        }

        // Include all sites, including the default/current one
        $sites = Site::all();
        
        // Filter source language options to only include languages that exist for selected entries
        $availableSourceLocales = [];
        
        // Try to get items from parameter or action context
        $itemsCollection = null;
        if (!empty($items)) {
            $itemsCollection = is_array($items) ? collect($items) : $items;
        } elseif (property_exists($this, 'items') && !empty($this->items)) {
            $itemsCollection = is_array($this->items) ? collect($this->items) : $this->items;
        }
        
        if ($itemsCollection && $itemsCollection->count() > 0) {
            foreach ($sites as $site) {
                $siteHandle = $site->handle();
                $siteLocale = $site->locale();
                
                // Check if at least one entry exists in this site
                $hasEntryInSite = false;
                foreach ($itemsCollection as $entry) {
                    if ($entry instanceof Entry && $entry->existsIn($siteHandle)) {
                        $hasEntryInSite = true;
                        break;
                    }
                }
                
                if ($hasEntryInSite) {
                    $availableSourceLocales[] = $siteLocale;
                }
            }
        } else {
            // If no items provided, include all sites (fallback)
            $availableSourceLocales = $sites->pluck('locale')->toArray();
        }
        
        // Filter site options to only include available source locales
        $sourceSiteOptions = $sites->filter(function ($site) use ($availableSourceLocales) {
            return in_array($site->locale(), $availableSourceLocales);
        })->mapWithKeys(function ($site) {
            return [$site->locale => $site->name() . ' (' . $site->locale . ')'];
        })->toArray();
        
        // All sites available for destination
        $destinationSiteOptions = $sites->mapWithKeys(function ($site) {
            return [$site->locale => $site->name() . ' (' . $site->locale . ')'];
        })->toArray();

        // Get current site locale (default source)
        $currentSite = Site::current();
        $defaultSourceLocale = $currentSite && in_array($currentSite->locale(), $availableSourceLocales) 
            ? $currentSite->locale() 
            : (!empty($availableSourceLocales) ? $availableSourceLocales[0] : $sites->first()?->locale());

        return [
            'source_language' => [
                'type' => 'select',
                'display' => __('Source language'),
                'options' => $sourceSiteOptions,
                'default' => $defaultSourceLocale,
            ],
            'destination_language' => [
                'type' => 'select',
                'display' => __('Destination language'),
                'options' => $destinationSiteOptions,
                'default' => $sites->firstWhere('locale', '!=', $defaultSourceLocale)?->locale ?? $sites->first()?->locale,
            ],
        ];
    }



    public function run($items, $values)
    {
        if (!$this->isMultisite()) {
            return [
                'callback' => ['errorCallback', __('This action is not available for single site installations.')]
            ];
        }

        $sourceLocale = $values['source_language'] ?? null;
        $destinationLocale = $values['destination_language'] ?? null;

        if (!$sourceLocale) {
            return [
                'callback' => ['errorCallback', __('Please select a source language.')]
            ];
        }

        if (!$destinationLocale) {
            return [
                'callback' => ['errorCallback', __('Please select a destination language.')]
            ];
        }

        if ($sourceLocale === $destinationLocale) {
            return [
                'callback' => ['errorCallback', __('Source and destination languages cannot be the same.')]
            ];
        }

        // Get source and destination sites
        $sourceSite = Site::all()->firstWhere('locale', $sourceLocale);
        $destinationSite = Site::all()->firstWhere('locale', $destinationLocale);
        
        if (!$sourceSite) {
            return [
                'callback' => ['errorCallback', __('Source site not found for locale: :locale', ['locale' => $sourceLocale])]
            ];
        }

        if (!$destinationSite) {
            return [
                'callback' => ['errorCallback', __('Destination site not found for locale: :locale', ['locale' => $destinationLocale])]
            ];
        }

        $translatedCount = 0;
        $updatedCount = 0;
        $skippedCount = 0;
        $errors = [];
        $overriddenEntries = []; // Track entries that were overridden
        
        // Ensure items is iterable (handle both arrays and collections)
        $itemsCollection = is_array($items) ? collect($items) : $items;
        $totalItems = $itemsCollection->count();

        // Check if any translations already exist in destination
        $hasExistingTranslations = false;
        $existingCount = 0;
        foreach ($itemsCollection as $entry) {
            if ($entry instanceof Entry && $entry->existsIn($destinationSite->handle())) {
                $hasExistingTranslations = true;
                $existingCount++;
            }
        }

        foreach ($itemsCollection as $entry) {
            try {
                // Ensure we have a valid entry
                if (!$entry instanceof Entry) {
                    $errors[] = __('Invalid entry provided');
                    continue;
                }
                
                // Get the source language entry
                $sourceEntry = null;
                $entrySiteHandle = $entry->site()->handle();
                
                if ($entrySiteHandle === $sourceSite->handle()) {
                    // Entry is already in source language
                    $sourceEntry = $entry;
                } else {
                    // Entry is in a different language, get the source language version
                    if ($entry->existsIn($sourceSite->handle())) {
                        $sourceEntry = $entry->in($sourceSite->handle());
                    } else {
                        $errors[] = __('Source language entry does not exist for entry :title', [
                            'title' => $entry->title()
                        ]);
                        continue;
                    }
                }
                
                if (!$sourceEntry) {
                    $errors[] = __('Failed to get source language entry for :title', [
                        'title' => $entry->title()
                    ]);
                    continue;
                }
                
                // Get or create the destination language entry
                $destinationEntry = null;
                $isExisting = false;
                
                // First try to get from the source entry (most reliable)
                if ($sourceEntry->existsIn($destinationSite->handle())) {
                    $destinationEntry = $sourceEntry->in($destinationSite->handle());
                    $isExisting = true;
                } else {
                    // Create new localization from source entry
                    $destinationEntry = $sourceEntry->makeLocalization($destinationSite->handle());
                }
                
                if (!$destinationEntry) {
                    if ($isExisting) {
                        $errors[] = __('Failed to load existing destination entry for :title', [
                            'title' => $sourceEntry->title()
                        ]);
                    } else {
                        $errors[] = __('Failed to create destination entry for :title', [
                            'title' => $sourceEntry->title()
                        ]);
                    }
                    continue;
                }
                
                // Ensure the destination entry is set to the correct site
                if ($destinationEntry->site()->handle() !== $destinationSite->handle()) {
                    $destinationEntry->site($destinationSite->handle());
                }
                
                // Convert SOURCE entry to markdown
                $markdownContent = $this->entryToMarkdown($sourceEntry);
                
                if (empty($markdownContent)) {
                    $errors[] = __('Failed to convert source entry :title to markdown', [
                        'title' => $sourceEntry->title()
                    ]);
                    continue;
                }
                
                // Translate the markdown content from source language to destination language
                $aiService = $this->getAiService();
                // Get destination language name for better AI understanding
                $destinationLanguageName = $destinationSite->name();
                
                \Log::info('Translating entry', [
                    'source_entry_id' => $sourceEntry->id(),
                    'source_site' => $sourceEntry->site()->handle(),
                    'source_locale' => $sourceLocale,
                    'destination_entry_id' => $destinationEntry->id(),
                    'destination_site' => $destinationEntry->site()->handle(),
                    'destination_locale' => $destinationLocale,
                    'destination_language_name' => $destinationLanguageName,
                ]);
                
                $translatedMarkdown = $aiService->translateMarkdown($markdownContent, $destinationLocale, $destinationLanguageName);
                
                if (empty($translatedMarkdown)) {
                    $errors[] = __('Translation failed for entry :title - empty response from AI', [
                        'title' => $sourceEntry->title()
                    ]);
                    \Log::error('Translation failed - empty response', [
                        'entry_id' => $sourceEntry->id(),
                        'destination_locale' => $destinationLocale,
                    ]);
                    continue;
                }
                
                \Log::info('Translation received', [
                    'entry_id' => $sourceEntry->id(),
                    'translated_length' => strlen($translatedMarkdown),
                ]);
                
                // Parse translated markdown back to destination entry fields
                $this->markdownToEntry($destinationEntry, $translatedMarkdown, $sourceEntry);
                
                // Copy non-translatable metadata (dates, status, etc.) - preserve existing if updating
                if (!$isExisting) {
                    // For new entries, set published to false (user needs to verify AI translation)
                    $destinationEntry->published(false);
                    if ($sourceEntry->date()) {
                        $destinationEntry->date($sourceEntry->date());
                    }
                } else {
                    // For existing entries, preserve the published status
                    // Don't change it - keep whatever it was before (already set when loading the entry)
                }
                
                // Ensure destination entry is in the correct site before saving
                if ($destinationEntry->site()->handle() !== $destinationSite->handle()) {
                    \Log::warning('Destination entry site mismatch, correcting', [
                        'entry_id' => $destinationEntry->id(),
                        'current_site' => $destinationEntry->site()->handle(),
                        'expected_site' => $destinationSite->handle(),
                    ]);
                    $destinationEntry->site($destinationSite->handle());
                }

                
                // Save the destination entry
                $saved = $destinationEntry->save();
                
                if (!$saved) {
                    $errors[] = __('Failed to save translated entry :title', [
                        'title' => $sourceEntry->title()
                    ]);
                    \Log::error('Failed to save destination entry', [
                        'entry_id' => $destinationEntry->id(),
                        'site' => $destinationEntry->site()->handle(),
                    ]);
                    continue;
                }
                
                \Log::info('Destination entry saved successfully', [
                    'entry_id' => $destinationEntry->id(),
                    'site' => $destinationEntry->site()->handle(),
                    'locale' => $destinationEntry->locale(),
                    'is_existing' => $isExisting,
                ]);
                
                if ($isExisting) {
                    $updatedCount++;
                    // Track overridden entries for warning message
                    $overriddenEntries[] = $destinationEntry;
                } else {
                    $translatedCount++;
                }
                
            } catch (\Exception $e) {
                $entryTitle = $entry instanceof Entry ? $entry->title() : 'Unknown';
                $errors[] = __('Error translating entry :title: :error', [
                    'title' => $entryTitle,
                    'error' => $e->getMessage()
                ]);
                \Log::error('Translation error', [
                    'entry' => $entryTitle,
                    'source_locale' => $sourceLocale,
                    'destination_locale' => $destinationLocale,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }

        // Build response message
        $parts = [];
        if ($translatedCount > 0) {
            $parts[] = __('Translated :count entry(ies)', ['count' => $translatedCount]);
        }
        if ($updatedCount > 0) {
            $parts[] = __('Updated :count existing translation(s)', ['count' => $updatedCount]);
        }
        
        $message = !empty($parts) ? implode(', ', $parts) : __('No entries processed');
        $message .= ' ' . __('(out of :total total)', ['total' => $totalItems]);
        
        // Add warning if existing translations were overridden
        if ($hasExistingTranslations && $existingCount > 0) {
            $destinationSiteName = $destinationSite->name();
            $warningMessage = __('Translation override warning', [
                'language' => $destinationSiteName,
                'count' => $existingCount
            ]);
            $message .= ' ' . $warningMessage;
        }
        
        if (count($errors) > 0) {
            $message .= ' ' . __('(:error_count error(s))', ['error_count' => count($errors)]);
        }
        
        // we use a callback to redirect to the edit url of the first item after the translation is complete (necessary to update the UI)
        // and to show a success message
        return [
            'callback' => ['successCallback', $items[0]->edit_url, $message],
        ];
    }

    /**
     * Check if translations exist for items in a destination locale
     * This method can be called from the action context with items already available
     */
    public function checkTranslationsExist($items, $destinationLocale)
    {
        // Get the site handle for the destination locale
        $destinationSite = Site::all()->firstWhere('locale', $destinationLocale);
        if (!$destinationSite) {
            return [
                'exists' => false,
                'count' => 0,
                'total' => 0
            ];
        }

        $itemsCollection = is_array($items) ? collect($items) : $items;
        $hasTranslations = false;
        $existingCount = 0;

        foreach ($itemsCollection as $entry) {
            if ($entry instanceof Entry && $entry->existsIn($destinationSite->handle())) {
                $hasTranslations = true;
                $existingCount++;
            }
        }

        return [
            'exists' => $hasTranslations,
            'count' => $existingCount,
            'total' => $itemsCollection->count()
        ];
    }

    /**
     * Get the AI service instance
     */
    private function getAiService()
    {
        $provider = config('statamic-ai-assistant.provider_name');
        
        if ($provider === 'infomaniak') {
            return new InfomaniakService();
        }
        
        return new GroqService();
    }

    /**
     * Convert entry to markdown format
     */
    private function entryToMarkdown($entry): string
    {
        $blueprint = $entry->blueprint();
        $fields = $blueprint->fields()->all();
        
        $data = [];
        
        foreach ($fields as $field) {
            $fieldHandle = $field->handle();
            $fieldValue = $entry->get($fieldHandle);
            
            if ($fieldValue !== null) {
                $data[$fieldHandle] = $fieldValue;
            }
        }
        
        // Convert to YAML front matter
        $yaml = Yaml::dump($data, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
        
        return "---\n" . $yaml . "---\n";
    }

    /**
     * Parse translated markdown back to entry fields
     */
    private function markdownToEntry($localizedEntry, string $translatedMarkdown, $originalEntry)
    {
        // Extract front matter - handle cases where LLM adds extra text
        // Try multiple patterns to find the YAML front matter
        $patterns = [
            '/^---\s*\n(.*?)\n---/s',  // Standard front matter
            '/---\s*\n(.*?)\n---/s',    // Front matter anywhere in text
            '/```yaml\s*\n(.*?)\n```/s', // Code block format
            '/```\s*\n(.*?)\n```/s',    // Generic code block
        ];
        
        $frontMatter = null;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $translatedMarkdown, $matches)) {
                $frontMatter = $matches[1];
                break;
            }
        }
        
        // If no front matter found, try parsing the whole response as YAML
        if ($frontMatter === null) {
            $frontMatter = trim($translatedMarkdown);
        }
        
        try {
            // Parse YAML front matter
            $translatedData = Yaml::parse($frontMatter);
            
            if (is_array($translatedData)) {
                foreach ($translatedData as $fieldHandle => $translatedValue) {
                    $this->setEntryField($localizedEntry, $fieldHandle, $translatedValue, $originalEntry);
                }
            }
        } catch (\Exception $e) {
            // If YAML parsing fails, fall back to original values
            // Log error but don't break the process
            \Log::warning("Failed to parse translated markdown: " . $e->getMessage());
        }
    }

    /**
     * Set field value on entry, preserving type and structure
     */
    private function setEntryField($entry, string $fieldHandle, $translatedValue, $originalEntry)
    {
        // Get original field value to determine type
        $originalValue = $originalEntry->get($fieldHandle);
        
        if ($originalValue === null) {
            return;
        }
        
        // YAML parser already handles types, but we ensure consistency
        if (is_array($originalValue) && !is_array($translatedValue)) {
            // If original was array but translated is not, keep original structure
            $entry->set($fieldHandle, $originalValue);
        } elseif (is_bool($originalValue) && !is_bool($translatedValue)) {
            // Ensure boolean type
            $entry->set($fieldHandle, (bool) $translatedValue);
        } elseif (is_numeric($originalValue) && !is_numeric($translatedValue)) {
            // Ensure numeric type
            $entry->set($fieldHandle, is_float($originalValue) ? (float) $translatedValue : (int) $translatedValue);
        } else {
            // Use translated value as-is (YAML parser handles types)
            $entry->set($fieldHandle, $translatedValue);
        }
    }

    private function isMultisite()
    {
        return Site::all()->count() > 1;
    }
}


