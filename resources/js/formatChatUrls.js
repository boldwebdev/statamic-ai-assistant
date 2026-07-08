/**
 * Aligns loosely with PromptUrlFetcher::extractPublicHttpUrls (http(s) only, word boundary).
 */
const HTTP_URL_RE = /\bhttps?:\/\/[^\s<>\]\}\)"'`]+/giu;

function escapeHtml(s) {
  return String(s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function trimUrlTrailingPunct(raw) {
  return raw.replace(/[.,;:!?)\]}'"]+$/u, '');
}

const BOLD_MARKDOWN_RE = /\*\*([^*\n]+?)\*\*/gu;

function formatBoldMarkdown(escapedText) {
  return escapedText.replace(BOLD_MARKDOWN_RE, '<strong class="eg-chat__bold">$1</strong>');
}

/**
 * Escape HTML, render **bold** markdown, and wrap detected URLs in
 * <strong class="eg-chat__url"> for safe v-html.
 *
 * @param {string|null|undefined} text
 * @returns {string}
 */
export function formatChatTextWithBoldUrls(text) {
  if (text == null) return '';
  const s = String(text);
  if (!s) return '';

  let out = '';
  let last = 0;
  const re = new RegExp(HTTP_URL_RE.source, 'giu');
  let m;
  while ((m = re.exec(s)) !== null) {
    out += formatBoldMarkdown(escapeHtml(s.slice(last, m.index)));
    const raw = m[0];
    const trimmed = trimUrlTrailingPunct(raw);
    const trailing = raw.slice(trimmed.length);
    out += `<strong class="eg-chat__url">${escapeHtml(trimmed)}</strong>`;
    out += formatBoldMarkdown(escapeHtml(trailing));
    last = m.index + raw.length;
  }
  out += formatBoldMarkdown(escapeHtml(s.slice(last)));
  return out;
}

function escapeRegExp(s) {
  return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/** "@asset:container::path" / "@folder:container::path" — always chip + hover hooks. */
const ASSET_FOLDER_MENTION_RE = /@(asset|folder):([a-z0-9_-]+)::([^<\s]+)/giu;

function assetFolderChipHtml(kind, container, path) {
  const ref = `${container}::${path}`;
  const fullTitle = `${kind}:${ref}`;
  return `<span class="eg-chat__chip eg-chat__chip--${kind}" data-mention-kind="${kind}" data-mention-ref="${escapeHtml(ref)}"><span class="eg-chat__chip-at">@</span>${escapeHtml(fullTitle)}</span>`;
}

/**
 * Wrap any "@asset:" / "@folder:" tokens (agent replies, typed refs, etc.).
 * Runs on escaped HTML and skips tokens already inside a chip span.
 *
 * @param {string} html
 * @returns {string}
 */
function wrapAssetFolderMentionChips(html) {
  return html.replace(ASSET_FOLDER_MENTION_RE, (match, kind, container, path, offset, full) => {
    const before = full.slice(Math.max(0, offset - 80), offset);
    if (before.includes('data-mention-ref=') || before.includes('eg-chat__chip-at')) {
      return match;
    }
    return assetFolderChipHtml(kind, container, path);
  });
}

/**
 * Wrap "@Title" tokens for known entry mentions in a colored chip span. Runs on
 * already-escaped HTML (post formatChatTextWithBoldUrls), matching against the
 * HTML-escaped titles so entities like "&amp;" line up. Longest titles first so
 * overlapping names don't partially match.
 *
 * @param {string} html  Safe HTML from formatChatTextWithBoldUrls.
 * @param {string[]} titles  Entry titles that were referenced via the picker.
 * @returns {string}
 */
function wrapEntryMentionChips(html, titles) {
  if (!Array.isArray(titles) || titles.length === 0) return html;

  const unique = [...new Set(
    titles
      .filter((t) => typeof t === 'string' && t.trim() !== '')
      .filter((t) => !t.startsWith('asset:') && !t.startsWith('folder:')),
  )].sort((a, b) => b.length - a.length);

  let out = html;
  for (const title of unique) {
    const escaped = escapeRegExp(escapeHtml(title));
    const re = new RegExp(`@${escaped}`, 'g');
    out = out.replace(
      re,
      `<span class="eg-chat__chip"><span class="eg-chat__chip-at">@</span>${escapeHtml(title)}</span>`,
    );
  }
  return out;
}

/**
 * Full chat message renderer: bold/URL formatting plus "@Title" entry chips.
 *
 * @param {string|null|undefined} text
 * @param {string[]} [mentionTitles]  Titles selected via the "@" entry picker.
 * @returns {string}
 */
export function formatChatMessageHtml(text, mentionTitles = []) {
  const html = formatChatTextWithBoldUrls(text);
  return wrapEntryMentionChips(wrapAssetFolderMentionChips(html), mentionTitles);
}
