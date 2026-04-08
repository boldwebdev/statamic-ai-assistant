<template>
  <div class="translation-page__targets" :class="wrapperClass">
    <label
      v-for="site in sites"
      :key="site.handle"
      class="translation-page__target-option"
      :class="{ 'translation-page__target-option--conflict': conflictActive(site) }"
    >
      <input
        type="checkbox"
        :value="site.locale"
        :checked="isSelected(site.locale)"
        @change="toggle(site.locale, $event.target.checked)"
      />
      <span class="translation-page__target-option-text">
        {{ displaySiteName(site) }}
        <span class="translation-page__target-locale">({{ site.locale }})</span>
        <span
          v-if="conflictActive(site)"
          class="translation-page__target-badge"
          :title="__('Target language already translated badge title')"
        >
          {{ __('Target language already translated badge') }}
        </span>
      </span>
    </label>
  </div>
</template>

<script>
import { normalizeDestinationLocales } from '../utils/normalizeDestinationLocales.js';

export default {
  props: {
    sites: {
      type: Array,
      default: () => [],
    },
    value: {
      type: Array,
      default: () => [],
    },
    conflictPredicate: {
      type: Function,
      default: null,
    },
    wrapperClass: {
      type: [String, Object, Array],
      default: '',
    },
  },

  methods: {
    displaySiteName(site) {
      if (!site || site.name === undefined || site.name === null) {
        return '';
      }
      return String(site.name).replace(/^[\u{1F1E6}-\u{1F1FF}]{2}\s*/u, '').trim();
    },

    conflictActive(site) {
      if (typeof this.conflictPredicate !== 'function') {
        return false;
      }
      return this.conflictPredicate(site);
    },

    isSelected(locale) {
      return normalizeDestinationLocales(this.value).includes(locale);
    },

    toggle(locale, checked) {
      const cur = [...normalizeDestinationLocales(this.value)];
      if (checked) {
        if (!cur.includes(locale)) {
          cur.push(locale);
        }
      } else {
        const i = cur.indexOf(locale);
        if (i !== -1) {
          cur.splice(i, 1);
        }
      }
      this.$emit('input', cur);
    },
  },
};
</script>
