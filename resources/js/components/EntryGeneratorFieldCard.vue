<template>
  <div
    class="eg-field-card"
    :class="{
      'eg-field-card--loading': loading,
      'eg-field-card--chat': variant === 'chat',
    }"
  >
    <div class="eg-field-card__header">
      <div class="eg-field-card__info">
        <span class="eg-field-card__label">{{ field.label }}</span>
        <span class="eg-field-card__type-badge">{{ field.type }}</span>
      </div>
      <div class="eg-field-card__actions">
        <button
          v-if="!showRefactor"
          type="button"
          class="eg-field-card__action-btn"
          :disabled="loading"
          :title="__('Regenerate')"
          @click="showRefactor = true"
        >
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="16" height="16">
            <path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clip-rule="evenodd" />
          </svg>
        </button>
      </div>
    </div>

    <div class="eg-field-card__body">
      <!-- Refactor prompt inline -->
      <div v-if="showRefactor" class="eg-field-card__refactor">
        <textarea
          ref="refactorInput"
          v-model="refactorPrompt"
          class="eg-field-card__refactor-input"
          :placeholder="__('Describe what to change...')"
          rows="2"
          @keyup.enter.ctrl="submitRefactor"
          @keyup.enter.meta="submitRefactor"
        ></textarea>
        <div class="eg-field-card__refactor-actions">
          <Button variant="default" size="sm" :text="__('Cancel')" @click="showRefactor = false" />
          <Button variant="primary" size="sm" :disabled="!refactorPrompt.trim() || loading" :text="__('Regenerate')" @click="submitRefactor" />
        </div>
      </div>

      <!-- Content display by field type -->
      <template v-else>
        <!-- Text field (editable input) -->
        <template v-if="field.type === 'text'">
          <input
            type="text"
            class="eg-field-card__text-input"
            :value="modelValue"
            @input="$emit('update:modelValue', $event.target.value)"
          />
        </template>

        <!-- HTML / Bard field (rendered preview) -->
        <template v-else-if="field.type === 'html'">
          <div class="eg-field-card__html-preview prose prose-sm" v-html="modelValue"></div>
        </template>

        <!-- Select field -->
        <template v-else-if="field.type === 'select'">
          <select
            class="eg-field-card__select"
            :value="modelValue"
            @change="$emit('update:modelValue', $event.target.value)"
          >
            <option value="">—</option>
            <option v-for="opt in (field.options || [])" :key="opt" :value="opt">{{ opt }}</option>
          </select>
        </template>

        <!-- Boolean field -->
        <template v-else-if="field.type === 'boolean'">
          <label class="eg-field-card__toggle-label">
            <input
              type="checkbox"
              :checked="modelValue"
              @change="$emit('update:modelValue', $event.target.checked)"
            />
            <span>{{ modelValue ? __('Yes') : __('No') }}</span>
          </label>
        </template>

        <!-- Date field -->
        <template v-else-if="field.type === 'date'">
          <input
            type="date"
            class="eg-field-card__date-input"
            :value="modelValue"
            @input="$emit('update:modelValue', $event.target.value)"
          />
        </template>

        <!-- Structured (replicator/grid) — show as JSON preview -->
        <template v-else-if="field.type === 'structured'">
          <div class="eg-field-card__structured-preview">
            <pre>{{ JSON.stringify(modelValue, null, 2) }}</pre>
          </div>
        </template>

        <!-- Asset field(s) — resolved container path(s) -->
        <template v-else-if="field.type === 'asset_description' || field.type === 'asset_descriptions'">
          <p class="eg-field-card__asset-hint">{{ __('Matched asset path(s) in the container') }}</p>
          <div class="eg-field-card__structured-preview">
            <pre>{{ assetPathsDisplay }}</pre>
          </div>
        </template>

        <!-- Fallback -->
        <template v-else>
          <textarea
            class="eg-field-card__textarea"
            :value="typeof modelValue === 'string' ? modelValue : JSON.stringify(modelValue)"
            rows="3"
            @input="$emit('update:modelValue', $event.target.value)"
          ></textarea>
        </template>
      </template>

      <AiModalLoadingOverlay
        :show="loading"
        compact
        :label="__('Regenerating...')"
      />
    </div>
  </div>
</template>

<script>
import { Button } from '@statamic/cms/ui';
import AiModalLoadingOverlay from './AiModalLoadingOverlay.vue';

export default {
  name: 'EntryGeneratorFieldCard',
  components: { Button, AiModalLoadingOverlay },
  props: {
    field: { type: Object, required: true },
    fieldHandle: { type: String, required: true },
    modelValue: { default: '' },
    loading: { type: Boolean, default: false },
    /** Compact styling inside chat drawer */
    variant: { type: String, default: null },
  },
  emits: ['update:modelValue', 'regenerate'],
  data() {
    return {
      showRefactor: false,
      refactorPrompt: '',
    };
  },
  computed: {
    assetPathsDisplay() {
      const v = this.modelValue;
      if (v == null || v === '') return '—';
      if (Array.isArray(v)) return v.join('\n');
      return String(v);
    },
  },
  methods: {
    submitRefactor() {
      if (!this.refactorPrompt.trim()) return;
      this.$emit('regenerate', {
        handle: this.fieldHandle,
        prompt: this.refactorPrompt,
      });
      this.showRefactor = false;
      this.refactorPrompt = '';
    },
  },
};
</script>
