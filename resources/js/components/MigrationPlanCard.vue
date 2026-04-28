<template>
  <div class="eg-migration-plan">
    <div v-if="store.migration.discovering" class="eg-chat__stream-panel" aria-live="polite" aria-busy="true">
      <div
        class="eg-chat__stream-panel__bar"
        role="progressbar"
        aria-valuemin="0"
        aria-valuemax="100"
        :aria-valuenow="migrationDiscoveryBarWidth"
        :aria-label="__('Discovering pages')"
      >
        <div class="eg-chat__stream-panel__fill" :style="{ width: migrationDiscoveryBarWidth + '%' }" />
      </div>
      <p class="eg-chat__stream-panel__title">{{ __('Discovering pages on the site…') }}</p>
      <p class="eg-chat__stream-panel__hint">{{ __('Reading sitemaps and URLs, then grouping paths for your review.') }}</p>
      <div
        v-if="discoveryActivityLog.length"
        ref="discoveryActivityScroll"
        class="eg-chat__activity"
      >
        <transition-group name="eg-activity" tag="ul" class="eg-chat__activity-list">
          <li
            v-for="row in discoveryActivityLog"
            :key="row.id"
            class="eg-chat__activity-line"
            v-html="chatHtml(row.text)"
          ></li>
        </transition-group>
      </div>
    </div>

    <div v-else-if="store.migration.discoveryError" class="eg-chat__notice eg-chat__notice--error">
      <p><strong>{{ __('Discovery failed') }}</strong></p>
      <p>{{ store.migration.discoveryError }}</p>
    </div>

    <template v-else-if="store.migration.discovery">
      <p class="eg-migration-plan__summary">
        {{ __('I found :n pages on :url, grouped into :c clusters. Assign each cluster below — unassigned clusters are skipped.', {
          n: (store.migration.discovery.urls || []).length,
          url: store.migration.detected && store.migration.detected.url,
          c: (store.migration.discovery.clusters || []).length,
        }) }}
      </p>

      <div v-if="store.migration.discovery.warnings && store.migration.discovery.warnings.length" class="eg-chat__notice eg-chat__notice--warn">
        <ul><li v-for="(w, i) in store.migration.discovery.warnings" :key="i">{{ w }}</li></ul>
      </div>

      <table class="eg-migration-plan__table">
        <thead>
          <tr>
            <th>{{ __('Pattern') }}</th>
            <th>{{ __('Pages') }}</th>
            <th>{{ __('Collection') }}</th>
            <th>{{ __('Blueprint') }}</th>
            <th>{{ __('Locale') }}</th>
          </tr>
        </thead>
        <tbody>
          <template v-for="c in clusters" :key="c.pattern">
            <tr>
              <td>
                <button
                  type="button"
                  class="eg-migration-plan__expand"
                  :aria-expanded="expanded[c.pattern] ? 'true' : 'false'"
                  :title="__('Show all URLs in this cluster')"
                  @click="toggleExpand(c.pattern)"
                >
                  <span class="eg-migration-plan__chevron" :class="{ 'eg-migration-plan__chevron--open': expanded[c.pattern] }">▸</span>
                  <code>{{ c.pattern }}</code>
                </button>
              </td>
              <td class="eg-migration-plan__count">
                <template v-if="selectedCount(c) === c.count">{{ c.count }}</template>
                <template v-else>{{ selectedCount(c) }} / {{ c.count }}</template>
              </td>
              <td>
                <select v-model="store.migration.mapping[c.pattern].collection" @change="onCollectionChange(c.pattern)">
                  <option value="">{{ __('— Skip —') }}</option>
                  <option v-for="col in collections" :key="col.handle" :value="col.handle">{{ col.title }}</option>
                </select>
              </td>
              <td>
                <template v-if="blueprintsFor(c.pattern).length <= 1">
                  <span class="eg-migration-plan__static">
                    {{ singleBlueprintLabel(c.pattern) }}
                  </span>
                </template>
                <select
                  v-else
                  v-model="store.migration.mapping[c.pattern].blueprint"
                  :disabled="!store.migration.mapping[c.pattern].collection"
                >
                  <option value="">{{ __('—') }}</option>
                  <option v-for="bp in blueprintsFor(c.pattern)" :key="bp.handle" :value="bp.handle">{{ bp.title }}</option>
                </select>
              </td>
              <td>
                <select v-model="store.migration.mapping[c.pattern].locale">
                  <option v-for="l in locales" :key="l.handle" :value="l.handle">{{ l.name }}</option>
                </select>
              </td>
            </tr>
            <tr v-if="expanded[c.pattern]" class="eg-migration-plan__expanded-row">
              <td colspan="5">
                <div class="eg-migration-plan__url-toolbar">
                  <button type="button" class="eg-migration-plan__linkbtn" @click="selectAll(c)">{{ __('Select all') }}</button>
                  <span class="eg-migration-plan__toolbar-sep">·</span>
                  <button type="button" class="eg-migration-plan__linkbtn" @click="deselectAll(c)">{{ __('Deselect all') }}</button>
                </div>
                <ul class="eg-migration-plan__urls">
                  <li v-for="u in c.urls" :key="u" class="eg-migration-plan__url-item">
                    <label>
                      <input
                        type="checkbox"
                        :checked="!store.migration.excludedUrls[u]"
                        @change="toggleUrl(u)"
                      />
                      <span :class="{ 'eg-migration-plan__url--excluded': store.migration.excludedUrls[u] }">{{ u }}</span>
                    </label>
                  </li>
                </ul>
              </td>
            </tr>
          </template>
        </tbody>
      </table>

      <p v-if="assignedCount === 0" class="eg-migration-plan__hint">
        {{ __('Pick a collection for at least one cluster to continue.') }}
      </p>

      <p v-if="store.migration.startError" class="eg-migration-plan__error">{{ store.migration.startError }}</p>
    </template>
  </div>
</template>

<script>
import { state, toggleMigrationUrl, setClusterInclusion } from '../store/entryGeneratorStore';
import { formatChatTextWithBoldUrls } from '../formatChatUrls.js';

export default {
  name: 'MigrationPlanCard',
  props: {
    /** When set (full-page layout), drives the discovery progress bar; drawer uses inline panel in EntryGeneratorPage. */
    discoveryBarPercent: {
      type: Number,
      default: null,
    },
  },
  setup() {
    return { store: state };
  },
  data() {
    return {
      expanded: {},
    };
  },
  computed: {
    migrationDiscoveryBarWidth() {
      if (this.discoveryBarPercent != null) {
        return this.discoveryBarPercent;
      }
      return 15;
    },
    discoveryActivityLog() {
      return this.store.activityLog.filter((row) => row.entryId == null);
    },
    clusters() {
      return this.store.migration.discovery?.clusters || [];
    },
    collections() {
      return this.store.migration.discovery?.collections || [];
    },
    locales() {
      return this.store.migration.discovery?.locales || [];
    },
    assignedCount() {
      const excluded = this.store.migration.excludedUrls || {};
      let total = 0;
      this.clusters.forEach((c) => {
        const m = this.store.migration.mapping[c.pattern];
        if (!m || !m.collection || !m.blueprint) return;
        (c.urls || []).forEach((u) => {
          if (!excluded[u]) total += 1;
        });
      });
      return total;
    },
  },
  watch: {
    'store.activityLog': {
      handler() {
        this.$nextTick(() => this.scrollDiscoveryActivity());
      },
      deep: true,
    },
  },
  methods: {
    chatHtml(text) {
      return formatChatTextWithBoldUrls(text);
    },
    scrollDiscoveryActivity() {
      const el = this.$refs.discoveryActivityScroll;
      if (el) el.scrollTop = el.scrollHeight;
    },
    onCollectionChange(pattern) {
      const bps = this.blueprintsFor(pattern);
      this.store.migration.mapping[pattern].blueprint = bps.length === 1 ? bps[0].handle : '';
    },
    blueprintsFor(pattern) {
      const handle = this.store.migration.mapping[pattern]?.collection;
      if (!handle) return [];
      const c = this.collections.find((col) => col.handle === handle);
      return c ? c.blueprints : [];
    },
    singleBlueprintLabel(pattern) {
      const bps = this.blueprintsFor(pattern);
      if (bps.length === 0) return this.__('—');
      if (bps.length === 1) return bps[0].title;
      return '';
    },
    toggleExpand(pattern) {
      this.expanded[pattern] = !this.expanded[pattern];
    },
    toggleUrl(url) {
      toggleMigrationUrl(url);
    },
    selectAll(cluster) {
      setClusterInclusion(cluster.urls || [], true);
    },
    deselectAll(cluster) {
      setClusterInclusion(cluster.urls || [], false);
    },
    selectedCount(cluster) {
      const excluded = this.store.migration.excludedUrls || {};
      return (cluster.urls || []).filter((u) => !excluded[u]).length;
    },
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
.eg-migration-plan { display: flex; flex-direction: column; gap: 12px; }
.eg-migration-plan__summary { margin: 0; font-size: 0.92rem; }
.eg-migration-plan__table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
.eg-migration-plan__table th,
.eg-migration-plan__table td { text-align: left; padding: 7px 10px; border-bottom: 1px solid currentColor; border-bottom-color: rgba(127, 127, 127, 0.15); vertical-align: top; }
.eg-migration-plan__table code { background: rgba(127, 127, 127, 0.12); padding: 2px 6px; border-radius: 4px; font-size: 0.88em; }
.eg-migration-plan__samples { display: flex; flex-direction: column; margin-top: 4px; gap: 2px; font-size: 0.72rem; opacity: 0.6; }
.eg-migration-plan__error { color: #ef4444; font-size: 0.85rem; margin: 4px 0 0; }
.eg-migration-plan__hint { font-size: 0.78rem; margin: 4px 0 0; text-align: right; opacity: 0.6; }
.eg-migration-plan__static { font-size: 0.88rem; padding: 0 2px; opacity: 0.85; }
.eg-migration-plan__expand {
  background: transparent;
  border: 0;
  padding: 0;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  color: inherit;
  font: inherit;
  text-align: left;
}
.eg-migration-plan__chevron {
  display: inline-block;
  font-size: 0.65rem;
  opacity: 0.6;
  transition: transform 0.15s ease;
}
.eg-migration-plan__chevron--open { transform: rotate(90deg); }
.eg-migration-plan__count { white-space: nowrap; font-variant-numeric: tabular-nums; }
.eg-migration-plan__expanded-row td { background: rgba(127, 127, 127, 0.05); padding: 10px 16px 14px; }
.eg-migration-plan__url-toolbar { display: flex; gap: 6px; align-items: center; margin-bottom: 6px; font-size: 0.78rem; }
.eg-migration-plan__linkbtn {
  background: transparent;
  border: 0;
  padding: 0;
  cursor: pointer;
  color: #6366f1;
  font: inherit;
  font-size: 0.78rem;
  text-decoration: underline;
}
.eg-migration-plan__linkbtn:hover { opacity: 0.85; }
.eg-migration-plan__toolbar-sep { opacity: 0.4; }
.eg-migration-plan__urls { list-style: none; padding: 0; margin: 0; max-height: 220px; overflow-y: auto; }
.eg-migration-plan__url-item label {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 3px 0;
  font-size: 0.78rem;
  cursor: pointer;
  line-height: 1.4;
}
.eg-migration-plan__url-item input[type="checkbox"] { flex-shrink: 0; }
.eg-migration-plan__url--excluded {
  text-decoration: line-through;
  opacity: 0.5;
}
</style>
