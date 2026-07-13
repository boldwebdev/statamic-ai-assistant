<template>
  <div class="eg-templates" role="dialog" :aria-label="__('Prompt templates')">
    <div class="eg-templates__head">
      <span class="eg-templates__title">{{ __('Prompt templates') }}</span>
      <button
        type="button"
        class="eg-templates__close"
        :title="__('Close')"
        :aria-label="__('Close')"
        @click="$emit('close')"
      >
        <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16" aria-hidden="true">
          <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
        </svg>
      </button>
    </div>

    <div class="eg-templates__intro">
      {{ __('Pick a ready-made prompt. I’ll ask a couple of questions when needed, then start a new chat.') }}
    </div>

    <div class="eg-templates__list">
      <template v-for="group in groups" :key="group.category">
        <div class="eg-templates__group">{{ __(group.label) }}</div>
        <button
          v-for="tpl in group.templates"
          :key="tpl.id"
          type="button"
          class="eg-templates__card"
          @click="$emit('select', tpl)"
        >
          <span class="eg-templates__card-icon" aria-hidden="true" v-html="iconSvg(tpl.icon)"></span>
          <span class="eg-templates__card-body">
            <span class="eg-templates__card-title">{{ __(tpl.title) }}</span>
            <span class="eg-templates__card-summary">{{ __(tpl.summary) }}</span>
            <span class="eg-templates__card-when">{{ __(tpl.whenToUse) }}</span>
          </span>
          <span class="eg-templates__card-chevron" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="16" height="16">
              <path d="M9 18l6-6-6-6" />
            </svg>
          </span>
        </button>
      </template>
    </div>
  </div>
</template>

<script>
import { templatesGroupedByCategory } from '../promptTemplates/index.js';

/**
 * Slide-over gallery of pre-made prompt templates, grouped by category.
 * Pure presentation: emits `select(template)` and `close`. The parent owns the
 * resulting chat (it builds the prompt and starts generation).
 */
export default {
  name: 'PromptTemplatesPanel',

  emits: ['select', 'close'],

  computed: {
    groups() {
      return templatesGroupedByCategory();
    },
  },

  methods: {
    iconSvg(name) {
      return ICONS[name] || ICONS.sparkles;
    },
  },
};

const ICONS = {
  'magnifying-glass': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" width="18" height="18"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>',
  'text-align-left': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" width="18" height="18"><path d="M4 6h16M4 12h10M4 18h13"/></svg>',
  'image': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" width="18" height="18"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="1.6"/><path d="M21 15l-5-5L5 21"/></svg>',
  'sparkles': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" width="18" height="18"><path d="M12 3v4M12 17v4M3 12h4M17 12h4M5.6 5.6l2.8 2.8M15.6 15.6l2.8 2.8M18.4 5.6l-2.8 2.8M8.4 15.6l-2.8 2.8"/></svg>',
};
</script>
