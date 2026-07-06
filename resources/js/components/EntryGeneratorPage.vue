<template>
  <!-- ═══ Agentic chat (drawer) — Claude-style flat layout ═══ -->
  <div v-if="drawer" ref="chatRoot" class="eg-chat">
    <div
      ref="chatStream"
      class="eg-chat__stream"
      @scroll.passive="onChatStreamScroll"
    >

      <!-- Agent: welcome -->
      <div class="eg-chat__msg eg-chat__msg--agent">
        <span class="eg-chat__sender">{{ __('BOLD agent') }}</span>
        <p>{{ __("Describe what you need. I'll choose the right collection and draft the entry for you.") }}</p>
      </div>

      <!-- Step 2: brief -->
      <div v-if="renderedStep === 2" class="eg-chat__msg eg-chat__msg--agent">
        <span class="eg-chat__sender">{{ __('BOLD agent') }}</span>
        <p>{{ __('Tell me what you want. Examples:') }}</p>
        <ul class="eg-chat__examples">
          <li>{{ __('“Create a page about our new pilot program”') }}</li>
          <li>{{ __('“Create an entry that takes exactly the same content as https://www.example.com/about-us”') }}</li>
        </ul>

        <div class="eg-chat__card">
          <div class="eg-chat__form-row">
            <label class="eg-chat__form-label">{{ __('Attachment') }}</label>
            <div
              class="eg-chat__drop"
              :class="{ 'eg-chat__drop--active': dragOver }"
              @dragover.prevent="dragOver = true"
              @dragleave="dragOver = false"
              @drop.prevent="handleDrop"
            >
              <template v-if="!attachedFile">
                <span class="eg-chat__drop-text">
                  {{ __('Drop PDF/TXT or') }}
                  <button type="button" class="eg-chat__drop-link" @click="$refs.fileInput.click()">{{ __('browse') }}</button>
                </span>
              </template>
              <template v-else>
                <span class="eg-chat__file">
                  <span class="eg-chat__file-name">{{ attachedFile.name }}</span>
                  <span class="eg-chat__file-meta">{{ formatFileSize(attachedFile.size) }}</span>
                  <button type="button" class="eg-chat__file-x" @click="removeFile">&times;</button>
                </span>
              </template>
              <input ref="fileInput" type="file" accept=".pdf,.txt" class="sr-only" @change="handleFileSelect" />
            </div>
          </div>
        </div>
      </div>

      <!-- Conversation history: one block per turn. Assistant turns render their
           entry cards inline beneath the reply. -->
      <template v-for="turn in chatTurns" :key="turn.key">
        <div v-if="turn.role === 'user'" class="eg-chat__msg eg-chat__msg--user">
          <div class="eg-chat__user-wrap">
            <span class="eg-chat__sender">{{ __('You') }}</span>
            <div class="eg-chat__bubble eg-chat__bubble--user">
              <p v-html="chatHtml(turn.text, turn.mention_titles)"></p>
            </div>
          </div>
        </div>
        <div v-else class="eg-chat__msg eg-chat__msg--agent">
          <span class="eg-chat__sender">{{ __('BOLD agent') }}</span>
          <div v-if="turn.kind === 'error'" class="eg-chat__notice eg-chat__notice--error">
            <p><strong>{{ __('Something went wrong') }}</strong></p>
            <p v-html="chatHtml(turn.text)"></p>
          </div>
          <p v-else v-html="chatHtml(turn.text)"></p>

          <div v-if="turn.cards.length" class="eg-chat__cards">
            <EntryGeneratorEntryCard
              v-for="(entry, idx) in turn.cards"
              :key="entry.id"
              :entry="entry"
              :index="idx"
              :auto-expand="turn.cards.length === 1"
              @save="(mode) => handleCardSave(entry.id, mode)"
              @retry="handleCardRetry(entry.id)"
              @discard="handleCardDiscard(entry.id)"
            />
          </div>
        </div>
      </template>

      <!-- Live turn in progress: planning indicator, not-yet-summarised cards,
           and the current-turn error (if any). -->
      <div v-if="renderedStep === 3 && store.planning" class="eg-chat__msg eg-chat__msg--agent">
        <span class="eg-chat__sender">{{ __('BOLD agent') }}</span>
        <div class="eg-chat__stream-panel" aria-live="polite" aria-busy="true">
          <div class="eg-chat__stream-panel__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="planProgressPercent" :aria-label="__('Planning')">
            <div class="eg-chat__stream-panel__fill" :style="{ width: planProgressPercent + '%' }" />
          </div>
          <p class="eg-chat__stream-panel__title">{{ __('Working out a plan…') }}</p>
          <p class="eg-chat__stream-panel__hint">{{ __('Figuring out how many entries to create and where each one fits.') }}</p>
          <div
            v-if="planningActivityLog.length"
            ref="activityScrollPlanning"
            class="eg-chat__activity"
          >
            <transition-group name="eg-activity" tag="ul" class="eg-chat__activity-list">
              <li
                v-for="row in planningActivityLog"
                :key="row.id"
                class="eg-chat__activity-line"
                v-html="chatHtml(row.text)"
              ></li>
            </transition-group>
          </div>
        </div>
      </div>

      <div v-if="renderedStep === 3 && liveCards.length" class="eg-chat__msg eg-chat__msg--agent">
        <span class="eg-chat__sender">{{ __('BOLD agent') }}</span>
        <div class="eg-chat__cards">
          <EntryGeneratorEntryCard
            v-for="(entry, idx) in liveCards"
            :key="entry.id"
            :entry="entry"
            :index="idx"
            :auto-expand="liveCards.length === 1"
            @save="(mode) => handleCardSave(entry.id, mode)"
            @retry="handleCardRetry(entry.id)"
            @discard="handleCardDiscard(entry.id)"
          />
        </div>
      </div>

      <!-- Current-turn error not yet folded into the transcript. -->
      <div v-if="renderedStep === 3 && showLiveGenerationError" class="eg-chat__msg eg-chat__msg--agent">
        <span class="eg-chat__sender">{{ __('BOLD agent') }}</span>
        <div class="eg-chat__notice eg-chat__notice--error">
          <p><strong>{{ __('Something went wrong') }}</strong></p>
          <p v-html="chatHtml(store.generationError)"></p>
        </div>
      </div>

      <!-- Stop confirmation (inline, above the composer) -->
      <div v-if="renderedStep === 3 && stopConfirmOpen" class="eg-chat__msg eg-chat__msg--agent">
        <span class="eg-chat__sender">{{ __('BOLD agent') }}</span>
        <div class="eg-chat__notice eg-chat__notice--warn eg-stop-confirm">
          <p>
            <strong>{{ __('Stop generation?') }}</strong>
            <template v-if="readyDraftedCount > 0">
              {{ __(':n entries are already drafted.', { n: readyDraftedCount }) }}
            </template>
          </p>
          <div class="eg-stop-confirm__actions">
            <Button variant="default" :text="__('Continue generating')" @click="cancelStopConfirm" />
            <Button variant="default" :text="__('Discard everything')" @click="confirmStop(false)" />
            <Button
              v-if="readyDraftedCount > 0"
              variant="primary"
              :text="__('Keep drafted (:n)', { n: readyDraftedCount })"
              @click="confirmStop(true)"
            />
          </div>
        </div>
      </div>

    </div>

    <!-- ── Bottom composer bar ── -->
    <div class="eg-chat__bar">
      <!-- Prompt input (step 2) -->
      <div v-if="renderedStep === 2 || renderedStep === 3" class="eg-chat__composer-wrap">
        <!-- @-mention entry picker -->
        <div v-if="mention.open" class="eg-chat__mention" role="listbox">
          <div class="eg-chat__mention-head">
            {{ __('Reference an entry') }}
          </div>
          <div v-if="mention.loading" class="eg-chat__mention-state">
            <span class="eg-chat__mention-spinner" aria-hidden="true" />
            {{ __('Searching…') }}
          </div>
          <template v-else>
            <ul v-if="mention.results.length" ref="mentionList" class="eg-chat__mention-list">
              <li
                v-for="(item, i) in mention.results"
                :key="item.id"
                role="option"
                :aria-selected="i === mention.activeIndex"
                class="eg-chat__mention-item"
                :class="{ 'is-active': i === mention.activeIndex }"
                @mousedown.prevent="selectMention(item)"
                @mousemove="mention.activeIndex = i"
              >
                <span class="eg-chat__mention-title">{{ item.title }}</span>
                <span class="eg-chat__mention-meta">{{ item.collection_title }}</span>
              </li>
            </ul>
            <div v-else class="eg-chat__mention-state">
              {{ __('No entries found') }}
            </div>
          </template>
        </div>

        <div class="eg-chat__composer">
          <textarea
            ref="promptTextarea"
            v-model="store.pendingPrompt"
            class="eg-chat__composer-input"
            :placeholder="composerPlaceholder"
            rows="1"
            :disabled="store.generating"
            @keydown="onComposerKeydown"
            @keyup="onComposerKeyup"
            @click="updateMentionFromCaret"
            @blur="onComposerBlur"
            @input="onComposerInput"
          />
          <button
            type="button"
            class="eg-chat__composer-send"
            :disabled="!canGenerate || store.generating"
            :title="__('Send')"
            @click="handleComposerSend"
          >
            <svg viewBox="0 0 20 20" fill="currentColor" width="18" height="18"><path d="M3.105 2.289a.75.75 0 00-.826.95l1.414 4.925A1.5 1.5 0 005.135 9.25h6.115a.75.75 0 010 1.5H5.135a1.5 1.5 0 00-1.442 1.086l-1.414 4.926a.75.75 0 00.826.95l14.095-5.638a.75.75 0 000-1.392L3.105 2.289z" /></svg>
          </button>
        </div>
      </div>

      <!-- Action buttons -->
      <div class="eg-chat__actions">
        <template v-if="renderedStep === 2 && !canGenerate">
          <span class="eg-chat__actions-hint">{{ __('Type at least 10 characters…') }}</span>
        </template>
        <template v-else-if="store.generating">
          <span class="eg-chat__actions-status">
            <span class="eg-chat__actions-pulse" />
            {{ generatingStatusText }}
          </span>
          <Button
            variant="default"
            :text="__('Stop')"
            :disabled="stopConfirmOpen"
            @click="requestStop"
          />
        </template>
        <template v-else-if="renderedStep === 3">
          <span v-if="readyOrSavedSummary" class="eg-chat__actions-summary">{{ readyOrSavedSummary }}</span>
          <Button variant="default" :text="__('New request')" @click="resetForNewRequest" />
          <Button
            v-if="canSaveAll"
            variant="default"
            :disabled="store.bulkSaving"
            :text="store.bulkSaving ? __('Saving…') : __('Save all as drafts')"
            @click="handleSaveAll('draft')"
          />
        </template>
      </div>
    </div>
  </div>

  <!-- ═══ Classic full-page layout ═══ -->
  <div
    v-else
    class="entry-generator"
    :class="{ 'entry-generator--busy': store.generating }"
  >
    <header class="entry-generator__hero">
      <div class="entry-generator__hero-icon" aria-hidden="true">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.35" stroke-linecap="round" stroke-linejoin="round">
          <path d="M15 4V2M15 16v-2M8 9h2M20 9h2M17.8 11.8L19 13M17.8 6.2L19 5M3 21l9-9M12.2 6.2L11 5" />
        </svg>
      </div>
      <div class="entry-generator__hero-text">
        <p class="entry-generator__eyebrow">{{ __('AI Assistant') }}</p>
        <h1 class="entry-generator__title">{{ __('Create entry with AI') }}</h1>
        <p class="entry-generator__subtitle">{{ __('Describe what you need and let AI generate the content for you') }}</p>
      </div>
    </header>

    <nav class="entry-generator__steps" :aria-label="__('Steps')">
      <div class="entry-generator__steps-track">
        <div
          v-for="(label, idx) in stepLabels"
          :key="idx"
          class="entry-generator__step"
          :class="{
            'entry-generator__step--active': step === idx + 1,
            'entry-generator__step--done': step > idx + 1,
          }"
        >
          <span class="entry-generator__step-number">
            <svg v-if="step > idx + 1" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="14" height="14">
              <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
            </svg>
            <span v-else>{{ idx + 1 }}</span>
          </span>
          <span class="entry-generator__step-label">{{ label }}</span>
        </div>
      </div>
    </nav>

    <div v-if="step === 1" class="entry-generator__panel">
      <h2 class="entry-generator__panel-title">{{ __('Select a collection') }}</h2>
      <p class="entry-generator__panel-desc">
        {{ availableBlueprints.length > 1
          ? __('Choose the collection and blueprint for your new entry.')
          : __('Choose the collection for your new entry.') }}
      </p>
      <div class="entry-generator__form-group">
        <label class="entry-generator__label">{{ __('Collection') }}</label>
        <select v-model="selectedCollection" class="entry-generator__select" @change="onCollectionChange">
          <option value="">{{ __('Select a collection...') }}</option>
          <option v-for="col in collections" :key="col.handle" :value="col.handle">{{ col.title }}</option>
        </select>
      </div>
      <div v-if="availableBlueprints.length > 1" class="entry-generator__form-group">
        <label class="entry-generator__label">{{ __('Blueprint') }}</label>
        <select v-model="selectedBlueprint" class="entry-generator__select">
          <option v-for="bp in availableBlueprints" :key="bp.handle" :value="bp.handle">{{ bp.title }}</option>
        </select>
      </div>
      <div class="entry-generator__panel-footer">
        <Button variant="primary" :disabled="!canProceedToPrompt" :text="__('Continue')" @click="goToPrompt" />
      </div>
    </div>

    <div v-if="step === 2" class="entry-generator__panel">
      <h2 class="entry-generator__panel-title">{{ __('Describe your entry') }}</h2>
      <p class="entry-generator__panel-desc">{{ __('Tell the AI what content you want. Be as specific as you can. Paste a URL to copy a page — its text and images are pulled in for you.') }}</p>
      <div class="entry-generator__form-group">
        <label class="entry-generator__label">{{ __('Prompt') }}</label>
        <textarea ref="promptTextarea" v-model="store.pendingPrompt" class="entry-generator__textarea" :placeholder="__('e.g. Create a page about our company event last Friday. Include a summary, key highlights, and a call to action...')" rows="6" @keyup.enter.ctrl="handleGenerate" @keyup.enter.meta="handleGenerate" />
      </div>
      <div class="entry-generator__form-group">
        <label class="entry-generator__label">{{ __('Attachment (optional)') }}</label>
        <div class="entry-generator__dropzone" :class="{ 'entry-generator__dropzone--active': dragOver }" @dragover.prevent="dragOver = true" @dragleave="dragOver = false" @drop.prevent="handleDrop">
          <template v-if="!attachedFile">
            <p class="entry-generator__dropzone-text">
              {{ __('Drag & drop a PDF or text file, or') }}
              <button type="button" class="entry-generator__dropzone-browse" @click="$refs.fileInput.click()">{{ __('browse') }}</button>
            </p>
            <p class="entry-generator__dropzone-hint">{{ __('PDF, TXT — max 10 MB') }}</p>
          </template>
          <template v-else>
            <div class="entry-generator__file-chip">
              <span>{{ attachedFile.name }}</span>
              <span class="entry-generator__file-size">{{ formatFileSize(attachedFile.size) }}</span>
              <button type="button" class="entry-generator__file-remove" @click="removeFile" :title="__('Remove')">&times;</button>
            </div>
          </template>
          <input ref="fileInput" type="file" accept=".pdf,.txt" class="sr-only" @change="handleFileSelect" />
        </div>
      </div>
      <div class="entry-generator__panel-footer">
        <Button variant="default" :text="__('Back')" @click="step = 1" />
        <Button variant="primary" :disabled="!canGenerate || store.generating" :loading="store.generating" :text="__('Generate')" @click="handleGenerate" />
      </div>
    </div>

    <div v-if="step === 3" class="entry-generator__panel">
      <template v-if="store.planning && store.entries.length === 0">
        <div class="entry-generator__generating">
          <AiModalLoadingOverlay :show="true" :label="__('Working out a plan…')" />
          <div class="entry-generator__panel-footer entry-generator__panel-footer--center">
            <Button variant="default" :text="__('Stop')" @click="requestStop" />
          </div>
        </div>
      </template>
      <template v-else-if="store.generationError">
        <div class="entry-generator__error" role="alert">
          <p class="entry-generator__error-title">{{ __('Generation failed') }}</p>
          <p v-html="chatHtml(store.generationError)"></p>
          <Button variant="primary" :text="__('Try again')" @click="resetForNewRequest" />
        </div>
      </template>
      <template v-else-if="store.plannerAnswer && store.entries.length === 0">
        <h2 class="entry-generator__panel-title">{{ __('Answer') }}</h2>
        <p v-html="chatHtml(store.plannerAnswer)"></p>
        <div class="entry-generator__panel-footer">
          <Button variant="primary" :text="__('New request')" @click="resetForNewRequest" />
        </div>
      </template>
      <template v-else>
        <h2 class="entry-generator__panel-title">{{ __('Preview') }}</h2>
        <p class="entry-generator__panel-desc">
          {{ store.entries.length > 1
            ? __('Review each generated entry. Save the ones you want; discard or retry the rest.')
            : __('Read-only snapshot of the generated content. Save to edit all fields in the Control Panel.') }}
        </p>

        <div v-if="store.plan && store.plan.warnings && store.plan.warnings.length" class="entry-generator__warnings" role="alert">
          <p class="entry-generator__warnings-title">{{ __('Notes') }}</p>
          <ul>
            <li v-for="(w, i) in store.plan.warnings" :key="i" v-html="chatHtml(w)"></li>
          </ul>
        </div>

        <div v-if="stopConfirmOpen" class="entry-generator__warnings eg-stop-confirm" role="alertdialog">
          <p class="entry-generator__warnings-title">{{ __('Stop generation?') }}</p>
          <p v-if="readyDraftedCount > 0">{{ __(':n entries are already drafted.', { n: readyDraftedCount }) }}</p>
          <p v-else>{{ __('No entries have been drafted yet.') }}</p>
          <div class="eg-stop-confirm__actions">
            <Button variant="default" :text="__('Continue generating')" @click="cancelStopConfirm" />
            <Button variant="default" :text="__('Discard everything')" @click="confirmStop(false)" />
            <Button
              v-if="readyDraftedCount > 0"
              variant="primary"
              :text="__('Keep drafted (:n)', { n: readyDraftedCount })"
              @click="confirmStop(true)"
            />
          </div>
        </div>

        <div class="entry-generator__cards">
          <EntryGeneratorEntryCard
            v-for="(entry, idx) in store.entries"
            :key="entry.id"
            :entry="entry"
            :index="idx"
            :auto-expand="store.entries.length === 1"
            @save="(mode) => handleCardSave(entry.id, mode)"
            @retry="handleCardRetry(entry.id)"
            @discard="handleCardDiscard(entry.id)"
          />
        </div>

        <div class="entry-generator__panel-footer">
          <Button variant="default" :text="__('Back')" @click="resetForNewRequest" />
          <Button
            v-if="store.generating"
            variant="default"
            :disabled="stopConfirmOpen"
            :text="__('Stop')"
            @click="requestStop"
          />
          <Button
            v-if="canSaveAll"
            variant="default"
            :disabled="store.bulkSaving"
            :loading="store.bulkSaving"
            :text="__('Save all as drafts')"
            @click="handleSaveAll('draft')"
          />
        </div>
      </template>
    </div>
  </div>
</template>

<script>
import axios from 'axios';
import { Button } from '@statamic/cms/ui';
import AiModalLoadingOverlay from './AiModalLoadingOverlay.vue';
import EntryGeneratorEntryCard from './EntryGeneratorEntryCard.vue';
import {
  state,
  STATUS,
  setActive,
  startGeneration,
  continueGeneration,
  saveCard,
  saveAll,
  retryCard,
  discardCard,
  reset as storeReset,
  stopGeneration,
  stopChatTurn,
  pushActivityLine,
  registerMention,
  setI18n,
  setToaster,
} from '../store/entryGeneratorStore.js';
import { formatChatMessageHtml } from '../formatChatUrls.js';

export default {
  name: 'EntryGeneratorPage',
  components: { Button, AiModalLoadingOverlay, EntryGeneratorEntryCard },

  props: {
    drawer: { type: Boolean, default: false },
    /**
     * Drawer-only: whether the panel is currently visible to the user.
     * When false during generation, the store treats itself as "minimized" and
     * surfaces completion via toasts + bubble badge instead of in-panel UX.
     */
    active: { type: Boolean, default: true },
  },

  data() {
    return {
      // Local UI-only state. Generation/composer state lives in the store.
      step: 1,
      collections: [],
      selectedCollection: '',
      selectedBlueprint: '',
      dragOver: false,

      // Local helper for the planning ticker (purely cosmetic — drives the planning bar).
      planTickerHandle: null,
      planTicker: 0,

      // True while the user is being asked to confirm a stop (keep / discard).
      stopConfirmOpen: false,

      // Drawer chat: only auto-scroll when the user is already near the bottom (or we force).
      chatStickToBottom: true,
      _chatStreamScrollSilent: false,

      // Rotating “under the hood” hints while the planner runs (drawer only).
      planningHintTimer: null,
      planningHintIdx: 0,
      planningHintKeys: [
        'Shaping the page in our mind…',
        'Writing your text and sections…',
        'Polishing titles and details…',
        'Almost ready to show you…',
      ],

      // @-mention entry picker (composer typeahead).
      mention: {
        open: false,
        query: '',
        results: [],
        activeIndex: 0,
        loading: false,
        start: 0, // index of the triggering "@" in the textarea value
        end: 0, // caret index when the query was captured
        _reqId: 0,
        _debounce: null,
        _blurTimer: null,
      },

      // Expose the reactive store to the template.
      store: state,
    };
  },

  computed: {
    stepLabels() {
      return [this.__('Configure'), this.__('Prompt'), this.__('Preview')];
    },

    availableBlueprints() {
      const col = this.collections.find((c) => c.handle === this.selectedCollection);
      return col ? col.blueprints : [];
    },

    canProceedToPrompt() {
      return this.selectedCollection && this.selectedBlueprint;
    },

    canGenerate() {
      return (this.store.pendingPrompt || '').trim().length >= 10;
    },

    /** Read-only proxy onto the store's attached file. */
    attachedFile() {
      return this.store.pendingAttachedFile;
    },

    promptRecap() {
      // Echo the user's message in full — never truncate what they typed.
      return (this.store.pendingPrompt || '').trim();
    },

    /** Composer hint: first message vs. a follow-up in an ongoing chat. */
    composerPlaceholder() {
      return this.drawer && this.store.chatSessionId
        ? this.__('Reply… e.g. “add a FAQ section to this entry”.')
        : this.__('Describe one or more entries you want to create… Paste a URL to copy a page (text and images).');
    },

    /**
     * Drawer step: when the store has plan/entries/error/generating, render step 3.
     * Otherwise render step 2 (composer).
     * Full-page mode uses `step` directly (1/2/3).
     */
    renderedStep() {
      if (!this.drawer) return this.step;
      const s = this.store;
      if (s.transcript.length > 0 || s.plan || s.entries.length > 0 || s.generating || s.planning || s.generationError || s.plannerAnswer) {
        return 3;
      }
      return 2;
    },

    /**
     * The conversation as renderable turns. Assistant turns carry the entry cards
     * they produced (matched by id) so cards appear inline beneath their reply.
     */
    chatTurns() {
      return (this.store.transcript || []).map((t, i) => ({
        key: `${i}-${t.role}`,
        role: t.role,
        text: t.text,
        kind: t.kind,
        cards: t.role === 'assistant' && Array.isArray(t.entry_ids)
          ? this.store.entries.filter((e) => t.entry_ids.includes(e.id))
          : [],
      }));
    },

    /**
     * Cards for the in-flight turn — not yet claimed by any transcript turn
     * (the assistant summary turn is appended only once the turn completes).
     */
    liveCards() {
      const claimed = new Set(
        (this.store.transcript || []).flatMap((t) => (Array.isArray(t.entry_ids) ? t.entry_ids : [])),
      );
      return this.store.entries.filter((e) => !claimed.has(e.id));
    },

    planProgressPercent() {
      const t = this.planTicker;
      return Math.min(85, 15 + t * 6);
    },

    /** Planning-phase lines only (per-entry stream lives on each entry card). */
    planningActivityLog() {
      return this.store.activityLog.filter((row) => row.entryId == null);
    },

    generatingStatusText() {
      if (this.store.planning) return this.__('Working out a plan…');
      const drafting = this.store.entries.filter((e) => e.status === STATUS.DRAFTING).length;
      const queued = this.store.entries.filter((e) => e.status === STATUS.QUEUED).length;
      if (drafting > 0 || queued > 0) {
        const total = this.store.entries.length;
        const ready = this.store.entries.filter((e) => [STATUS.READY, STATUS.SAVED].includes(e.status)).length;
        return this.__('Drafting :done of :total…', { done: ready + drafting, total });
      }
      return this.__('Generating…');
    },

    canSaveAll() {
      return this.store.entries.filter((e) => e.status === STATUS.READY).length > 1;
    },

    /** Number of entries that have finished drafting and are usable if the user stops now. */
    readyDraftedCount() {
      return this.store.entries.filter((e) => e.status === STATUS.READY).length;
    },

    readyOrSavedSummary() {
      if (this.store.entries.length <= 1) return '';
      const saved = this.store.entries.filter((e) => e.status === STATUS.SAVED).length;
      const ready = this.store.entries.filter((e) => e.status === STATUS.READY).length;
      const failed = this.store.entries.filter((e) => e.status === STATUS.FAILED).length;
      const parts = [];
      if (saved) parts.push(this.__(':n saved', { n: saved }));
      if (ready) parts.push(this.__(':n ready', { n: ready }));
      if (failed) parts.push(this.__(':n failed', { n: failed }));
      return parts.join(' · ');
    },

    /** Avoid duplicating an error already folded into the chat transcript. */
    showLiveGenerationError() {
      if (!this.store.generationError) return false;
      const transcript = this.store.transcript || [];
      const last = transcript[transcript.length - 1];
      return !(last?.role === 'assistant' && last?.kind === 'error');
    },
  },

  watch: {
    renderedStep(newVal, oldVal) {
      this.$nextTick(() => {
        if (!this.drawer) return;
        const stepChanged = oldVal !== undefined && newVal !== oldVal;
        this.scrollChatToBottom(stepChanged);
      });
    },
    'store.entries': {
      handler() {
        this.$nextTick(() => this.scrollChatToBottom());
      },
      deep: true,
    },
    'store.transcript': {
      handler() {
        this.$nextTick(() => this.scrollChatToBottom());
      },
      deep: true,
    },
    'store.planning'(now) {
      if (now) {
        this.startPlanTicker();
        this.startPlanningHints();
      } else {
        this.stopPlanTicker();
        this.stopPlanningHints();
      }
    },
    'store.activityLog': {
      handler() {
        this.$nextTick(() => {
          this.scrollActivityPanels();
          this.scrollChatToBottom();
        });
      },
      deep: true,
    },
    active: {
      immediate: true,
      handler(now) {
        if (!this.drawer) return;
        setActive(now);
        if (now) {
          this.chatStickToBottom = true;
          this.$nextTick(() => this.scrollChatToBottom(true));
        }
      },
    },
  },

  mounted() {
    setI18n((s, opts) => this.__(s, opts || {}));
    setToaster({
      success: (m) => Statamic.$toast?.success?.(m),
      error: (m) => Statamic.$toast?.error?.(m),
    });
    this.initializePage();
    if (this.store.planning) {
      this.startPlanTicker();
      this.startPlanningHints();
    }
  },

  beforeUnmount() {
    this.stopPlanTicker();
    this.stopPlanningHints();
    clearTimeout(this.mention._debounce);
    clearTimeout(this.mention._blurTimer);
    // NOTE: We deliberately do NOT abort the in-flight stream here. The store
    // owns it and it must survive component teardown so generation continues
    // when the user navigates away.
    if (this.drawer) setActive(false);
  },

  methods: {
    chatHtml(text, mentionTitles) {
      const titles = Array.isArray(mentionTitles) && mentionTitles.length
        ? mentionTitles
        : this.store.mentionedTitles;
      return formatChatMessageHtml(text, titles);
    },

    async initializePage() {
      await this.loadCollections();
      const params = new URLSearchParams(window.location.search);
      const preselect = params.get('collection');
      if (preselect) {
        this.selectedCollection = preselect;
        this.onCollectionChange();
      }
      if (this.drawer) {
        // Drawer always lands on the composer or the cards (driven by renderedStep).
        this.step = 2;
      }
    },

    /** Distance from bottom of `el` in px; smaller = nearer the end. */
    chatStreamDistanceFromBottom(el) {
      if (!el) return 0;
      return el.scrollHeight - el.scrollTop - el.clientHeight;
    },

    onChatStreamScroll() {
      if (!this.drawer || this._chatStreamScrollSilent) return;
      const el = this.$refs.chatStream;
      if (!el) return;
      const threshold = 80;
      this.chatStickToBottom = this.chatStreamDistanceFromBottom(el) <= threshold;
    },

    /**
     * @param {boolean} force  When true, always jump to bottom (new run, drawer opened, step change).
     */
    scrollChatToBottom(force = false) {
      if (!this.drawer) return;
      const el = this.$refs.chatStream;
      if (!el) return;
      if (!force && !this.chatStickToBottom) return;
      this._chatStreamScrollSilent = true;
      el.scrollTop = el.scrollHeight;
      this.chatStickToBottom = true;
      requestAnimationFrame(() => {
        requestAnimationFrame(() => {
          this._chatStreamScrollSilent = false;
        });
      });
    },

    scrollActivityPanels() {
      const el = this.$refs.activityScrollPlanning;
      if (el) el.scrollTop = el.scrollHeight;
    },

    startPlanningHints() {
      this.stopPlanningHints();
      if (!this.drawer) return;
      this.planningHintIdx = 0;
      this.planningHintTimer = setInterval(() => {
        if (!this.store.planning) return;
        const key = this.planningHintKeys[this.planningHintIdx % this.planningHintKeys.length];
        this.planningHintIdx += 1;
        pushActivityLine(this.__(key), null);
      }, 1300);
    },

    stopPlanningHints() {
      if (this.planningHintTimer) {
        clearInterval(this.planningHintTimer);
        this.planningHintTimer = null;
      }
    },

    async loadCollections() {
      try {
        const { data } = await axios.get('/cp/ai-generate/collections');
        this.collections = data.collections;
        if (this.selectedCollection) this.onCollectionChange();
      } catch (e) {
        console.error('Load collections error:', e.response?.data || e.message);
        Statamic.$toast.error(this.__('Could not load collections.'));
      }
    },

    onCollectionChange() {
      const bps = this.availableBlueprints;
      this.selectedBlueprint = bps.length === 1 ? bps[0].handle : (bps[0]?.handle || '');
    },

    goToPrompt() {
      if (!this.canProceedToPrompt) return;
      this.step = 2;
    },

    startPlanTicker() {
      this.stopPlanTicker();
      this.planTicker = 0;
      this.planTickerHandle = setInterval(() => { this.planTicker += 1; }, 600);
    },

    stopPlanTicker() {
      if (this.planTickerHandle) {
        clearInterval(this.planTickerHandle);
        this.planTickerHandle = null;
      }
    },

    resetForNewRequest() {
      storeReset();
      if (!this.drawer) this.step = 2;
      this.chatStickToBottom = true;
    },

    /**
     * Composer keydown: when the mention picker is open, arrows/enter/tab/escape
     * drive it. Otherwise Enter sends (Shift+Enter = newline).
     */
    onComposerKeydown(event) {
      if (this.mention.open) {
        if (event.key === 'ArrowDown') {
          event.preventDefault();
          this.moveMention(1);
          return;
        }
        if (event.key === 'ArrowUp') {
          event.preventDefault();
          this.moveMention(-1);
          return;
        }
        if (event.key === 'Enter' || event.key === 'Tab') {
          const item = this.mention.results[this.mention.activeIndex];
          if (item) {
            event.preventDefault();
            this.selectMention(item);
            return;
          }
        }
        if (event.key === 'Escape') {
          event.preventDefault();
          this.closeMention();
          return;
        }
      }

      if (event.key === 'Enter') {
        if (event.shiftKey) return;
        event.preventDefault();
        this.handleComposerSend();
      }
    },

    onComposerInput(event) {
      this.autoResize(event);
      this.updateMentionFromCaret();
    },

    onComposerKeyup(event) {
      // Navigation keys are handled in keydown; don't let them re-open/reset.
      if (this.mention.open && ['ArrowDown', 'ArrowUp', 'Enter', 'Tab', 'Escape'].includes(event.key)) {
        return;
      }
      this.updateMentionFromCaret();
    },

    onComposerBlur() {
      // Delay so a mousedown selection on a list item still registers.
      this.mention._blurTimer = setTimeout(() => this.closeMention(), 120);
    },

    /**
     * Recompute the active "@query" from the caret position. Opens the picker
     * when the caret sits inside a mention token started by "@" at a word
     * boundary; closes it otherwise.
     */
    updateMentionFromCaret() {
      const el = this.$refs.promptTextarea;
      if (!el || this.store.generating) return this.closeMention();

      const value = el.value || '';
      const caret = el.selectionStart ?? value.length;
      const upto = value.slice(0, caret);
      const at = upto.lastIndexOf('@');

      if (at === -1) return this.closeMention();

      const charBefore = at === 0 ? '' : upto[at - 1];
      if (charBefore && !/\s/.test(charBefore)) return this.closeMention();

      // The active query is a single whitespace-free token. Once the user types a
      // space, the mention is considered finished (so "@Eden Tea Time and …" stops
      // querying), while an already-inserted "@Title" with spaces is just text.
      const query = upto.slice(at + 1);
      if (/\s/.test(query) || query.length > 40) return this.closeMention();

      this.mention.start = at;
      this.mention.end = caret;
      this.mention.query = query;
      this.mention.open = true;
      this.searchMentions(query);
    },

    searchMentions(query) {
      this.mention.loading = true;
      clearTimeout(this.mention._debounce);
      this.mention._debounce = setTimeout(async () => {
        const reqId = ++this.mention._reqId;
        try {
          const { data } = await axios.get('/cp/ai-generate/entry-search', {
            params: { q: query, limit: 8 },
          });
          if (reqId !== this.mention._reqId) return;
          this.mention.results = Array.isArray(data.results) ? data.results : [];
          this.mention.activeIndex = 0;
        } catch (e) {
          if (reqId !== this.mention._reqId) return;
          this.mention.results = [];
        } finally {
          if (reqId === this.mention._reqId) this.mention.loading = false;
        }
      }, 160);
    },

    moveMention(direction) {
      const n = this.mention.results.length;
      if (!n) return;
      this.mention.activeIndex = (this.mention.activeIndex + direction + n) % n;
      this.$nextTick(() => {
        const list = this.$refs.mentionList;
        const active = list?.children?.[this.mention.activeIndex];
        active?.scrollIntoView?.({ block: 'nearest' });
      });
    },

    selectMention(item) {
      const el = this.$refs.promptTextarea;
      const value = this.store.pendingPrompt || '';
      const token = `@${item.title}`;
      const before = value.slice(0, this.mention.start);
      const after = value.slice(this.mention.end);
      const insert = `${token}${after.startsWith(' ') ? '' : ' '}`;

      this.store.pendingPrompt = before + insert + after;
      registerMention(item.title);
      this.closeMention();

      this.$nextTick(() => {
        if (!el) return;
        const pos = (before + insert).length;
        el.focus();
        el.setSelectionRange(pos, pos);
        this.autoResize({ target: el });
      });
    },

    closeMention() {
      clearTimeout(this.mention._blurTimer);
      clearTimeout(this.mention._debounce);
      this.mention._reqId += 1; // invalidate any in-flight request
      this.mention.open = false;
      this.mention.loading = false;
      this.mention.results = [];
      this.mention.query = '';
      this.mention.activeIndex = 0;
    },

    handleComposerSend() {
      if (!this.canGenerate || this.store.generating) return;

      this.closeMention();

      if (this.drawer && this.store.chatSessionId) {
        continueGeneration(this.store.pendingPrompt);
        this.resetComposerHeight();
        this.chatStickToBottom = true;
        return;
      }

      this.handleGenerate();
    },

    handleGenerate() {
      if (!this.canGenerate || this.store.generating) return;

      const prompt = (this.store.pendingPrompt || '').trim();

      const useAutoTarget = this.drawer && !this.selectedCollection;

      startGeneration({
        prompt,
        attachedFile: this.store.pendingAttachedFile,
        useAutoTarget,
        collection: this.selectedCollection,
        blueprint: this.selectedBlueprint,
      });
      if (this.drawer) this.resetComposerHeight();
      this.chatStickToBottom = true;
    },

    cardStatusOf(id) {
      const card = this.store.entries.find((e) => e.id === id);
      return card ? card.status : STATUS.QUEUED;
    },

    handleCardSave(id, mode) { saveCard(id, mode); },
    handleSaveAll(mode) { saveAll(mode); },
    handleCardRetry(id) { retryCard(id); },
    handleCardDiscard(id) { discardCard(id); },

    requestStop() {
      // Drawer chat: stop just this turn, keeping history + prior cards.
      if (this.drawer) {
        stopChatTurn();
        this.stopConfirmOpen = false;
        return;
      }
      // Full-page wizard: no drafted entries yet → stop straight away.
      if (this.readyDraftedCount === 0) {
        stopGeneration({ keepReady: false });
        return;
      }
      this.stopConfirmOpen = true;
    },

    confirmStop(keepReady) {
      this.stopConfirmOpen = false;
      stopGeneration({ keepReady });
    },

    cancelStopConfirm() {
      this.stopConfirmOpen = false;
    },

    autoResize(e) {
      const el = e.target;
      el.style.height = 'auto';
      el.style.height = Math.min(el.scrollHeight, 160) + 'px';
    },

    resetComposerHeight() {
      this.$nextTick(() => {
        const el = this.$refs.promptTextarea;
        if (el) el.style.height = 'auto';
      });
    },

    handleDrop(e) {
      this.dragOver = false;
      const files = e.dataTransfer.files;
      if (files.length > 0) this.setFile(files[0]);
    },

    handleFileSelect(e) {
      if (e.target.files.length > 0) this.setFile(e.target.files[0]);
    },

    setFile(file) {
      const ext = file.name.split('.').pop().toLowerCase();
      if (!['pdf', 'txt'].includes(ext)) {
        Statamic.$toast.error(this.__('Only PDF and TXT files are supported.'));
        return;
      }
      if (file.size > 10 * 1024 * 1024) {
        Statamic.$toast.error(this.__('File is too large. Maximum 10 MB.'));
        return;
      }
      this.store.pendingAttachedFile = file;
    },

    removeFile() {
      this.store.pendingAttachedFile = null;
      if (this.$refs.fileInput) this.$refs.fileInput.value = '';
    },

    formatFileSize(bytes) {
      if (bytes < 1024) return `${bytes} B`;
      if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
      return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
    },
  },
};
</script>

