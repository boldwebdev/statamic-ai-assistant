<template>
  <div v-if="session" class="eg-migration-progress">
    <div class="eg-migration-progress__header">
      <p class="eg-migration-progress__title">
        <template v-if="session.status === 'running'">{{ __('Migrating :url…', { url: session.site_url }) }}</template>
        <template v-else-if="session.status === 'completed'">{{ __('Migration finished.') }}</template>
        <template v-else-if="session.status === 'cancelled'">{{ __('Migration cancelled.') }}</template>
        <template v-else>{{ __('Migration :status', { status: session.status }) }}</template>
      </p>
      <span class="eg-migration-progress__label">
        {{ __(':done / :total', { done: doneCount, total: session.total }) }}
      </span>
    </div>

    <div class="eg-migration-progress__bar">
      <div class="eg-migration-progress__fill" :style="{ width: progressPct + '%' }"></div>
    </div>

    <div class="eg-migration-progress__stats">
      <span class="eg-stat eg-stat--ok">✓ {{ session.counts.completed }}</span>
      <span class="eg-stat eg-stat--run">⟳ {{ session.counts.running }}</span>
      <span class="eg-stat eg-stat--skip">↷ {{ session.counts.skipped }}</span>
      <span class="eg-stat eg-stat--err">✕ {{ session.counts.failed }}</span>
    </div>

    <details class="eg-migration-progress__details">
      <summary>{{ __('Per-page status') }}</summary>
      <ul class="eg-migration-progress__pages">
        <li
          v-for="page in pagesList"
          :key="page.url"
          :class="'eg-migration-progress__page eg-migration-progress__page--' + page.status"
        >
          <a class="eg-migration-progress__url" :href="page.url" target="_blank" rel="noopener">{{ page.url }}</a>
          <span class="eg-migration-progress__status">{{ page.status }}</span>
          <a
            v-if="page.entry_id"
            class="eg-migration-progress__entry"
            :href="'/cp/collections/' + page.collection + '/entries/' + page.entry_id"
            target="_blank"
            rel="noopener"
          >
            {{ __('Edit draft') }}
          </a>
          <span v-if="page.error" class="eg-migration-progress__err">{{ page.error }}</span>
        </li>
      </ul>
    </details>

  </div>
</template>

<script>
import { state } from '../store/entryGeneratorStore';

export default {
  name: 'MigrationProgressCard',
  emits: ['cancel', 'retry', 'new-request'],
  setup() {
    return { store: state };
  },
  computed: {
    session() { return this.store.migration.session; },
    doneCount() {
      if (!this.session) return 0;
      const c = this.session.counts;
      return c.completed + c.failed + c.skipped;
    },
    progressPct() {
      if (!this.session || !this.session.total) return 0;
      return Math.round((this.doneCount / this.session.total) * 100);
    },
    pagesList() {
      if (!this.session) return [];
      return Object.values(this.session.pages);
    },
    isRunning() {
      return this.session && this.session.status === 'running';
    },
    canRetry() {
      return this.session && this.session.counts.failed > 0 && !this.isRunning;
    },
  },
  methods: {
    __(key, replacements) {
      return this.$t ? this.$t(key, replacements) : this._fallbackT(key, replacements);
    },
    _fallbackT(key, replacements) {
      let out = key;
      if (replacements) {
        Object.keys(replacements).forEach((k) => {
          out = out.split(':' + k).join(replacements[k]);
        });
      }
      return out;
    },
  },
};
</script>

<style scoped>
.eg-migration-progress { display: flex; flex-direction: column; gap: 10px; }
.eg-migration-progress__header { display: flex; align-items: baseline; justify-content: space-between; gap: 10px; }
.eg-migration-progress__title { margin: 0; font-weight: 500; }
.eg-migration-progress__label { font-size: 0.85rem; opacity: 0.65; white-space: nowrap; }
.eg-migration-progress__bar { height: 6px; background: rgba(127, 127, 127, 0.18); border-radius: 4px; overflow: hidden; }
.eg-migration-progress__fill { height: 100%; background: #6366f1; transition: width 0.3s ease; }
.eg-migration-progress__stats { display: flex; gap: 14px; font-size: 0.85rem; }
.eg-stat--ok { color: #10b981; }
.eg-stat--run { color: #818cf8; }
.eg-stat--skip { color: #f59e0b; }
.eg-stat--err { color: #ef4444; }
.eg-migration-progress__details summary { cursor: pointer; font-size: 0.85rem; opacity: 0.65; }
.eg-migration-progress__pages { list-style: none; padding: 6px 0 0; margin: 0; max-height: 240px; overflow-y: auto; }
.eg-migration-progress__page { display: grid; grid-template-columns: 1fr auto auto; gap: 8px; align-items: baseline; padding: 4px 0; border-bottom: 1px solid rgba(127, 127, 127, 0.12); font-size: 0.78rem; }
.eg-migration-progress__url { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; opacity: 0.85; }
.eg-migration-progress__status { font-family: monospace; opacity: 0.7; }
.eg-migration-progress__page--failed .eg-migration-progress__status { color: #ef4444; opacity: 1; }
.eg-migration-progress__page--completed .eg-migration-progress__status { color: #10b981; opacity: 1; }
.eg-migration-progress__err { grid-column: 1 / -1; color: #ef4444; font-size: 0.72rem; }
</style>
