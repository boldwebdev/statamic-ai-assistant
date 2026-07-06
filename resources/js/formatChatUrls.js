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
