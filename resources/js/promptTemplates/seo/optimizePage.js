/**
 * SEO — Optimize an existing page around a focus keyword.
 *
 * Reads the entry, rewrites title / hero lead / seo_title / seo_description,
 * preserves facts and locales, updates in place.
 *
 * NOTE: Strings are English source / translation keys. The UI localizes them
 * via this.__(...); buildPrompt works on English values so it is locale-stable.
 */
export default {
  id: 'seo-optimize-page',
  category: 'seo',
  icon: 'magnifying-glass',
  title: 'Optimize a page for SEO',
  summary: 'Rewrite the title, hero lead and SEO fields around a focus keyword.',
  whenToUse: 'Use when a page exists but ranks poorly or lacks a clear keyword focus.',
  questions: [
    {
      id: 'page',
      label: 'Which page should I optimize?',
      type: 'entry',
      required: true,
    },
    {
      id: 'keyword',
      label: 'Focus keyword or phrase',
      type: 'text',
      required: false,
      placeholder: 'e.g. “wellness weekend Thunersee”',
      help: 'Leave empty and I will infer it from the page content.',
    },
  ],
  buildPrompt(answers) {
    const page = answers.page;
    const target = page?.title ? `the existing page @${page.title}` : 'the page I name below';
    const kw = answers.keyword
      ? ` Focus keyword: "${answers.keyword}". Weave it naturally into the title and hero lead — never stuff it.`
      : ' Infer the most fitting focus keyword from the page content and tell me which one you chose.';

    return [
      `Optimize ${target} for search engines without changing its meaning, facts, prices, or dates.`,
      kw,
      `Read the entry first with read_entry, then use update_entry_job to:`,
      `- write an SEO-friendly title (≤60 characters) and a concise hero lead that naturally contains the focus keyword,`,
      `- fill seo_title and seo_description (meta description ≤155 characters) while preserving the page's intent,`,
      `- improve heading hierarchy where the blueprint allows it.`,
      `Keep every change in the page's own locales — translate yourself, do not just transliterate.`,
      `End with a short summary of what you changed and which keyword you targeted.`,
    ].filter(Boolean).join(' ');
  },
};
