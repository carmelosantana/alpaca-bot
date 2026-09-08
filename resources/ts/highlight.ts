/**
 * A small syntax highlighter for the code blocks in a reply (Kanboard #613). Each language is
 * an ordered table of patterns; they are joined into one alternation, so at any position the
 * first pattern that matches wins and spans never nest. The source is escaped as it is
 * tokenised, never before: a pattern sees the raw code, the output only ever holds escaped
 * text. An unknown language is escaped and nothing else.
 */
/**
 * A token class and its pattern source. Non-capturing groups only (`(?:…)`): highlight() wraps
 * each source in one capturing group and reads the class off the index of the group that
 * matched, so a capturing group inside a source would shift every class after it.
 */
type Pattern = [cls: string, source: string];

const CMT_SLASH = String.raw`\/\/[^\n]*|\/\*[\s\S]*?\*\/`;
const CMT_HASH = String.raw`#[^\n]*`;
const STR = String.raw`"(?:\\.|[^"\\\n])*"|'(?:\\.|[^'\\\n])*'`;
const STR_TPL = String.raw`\x60(?:\\.|[^\x60\\])*\x60`;
const NUM = String.raw`\b(?:0x[\da-fA-F]+|\d+(?:\.\d+)?)\b`;
const FN = String.raw`\b[A-Za-z_]\w*(?=\s*\()`;
const kw = (words: string): string => String.raw`\b(?:${words.trim().split(/\s+/).join('|')})\b`;

const JS_KW = kw('async await break case catch class const continue debugger default delete do else enum export extends false finally for from function if implements import in instanceof interface let new null of package private protected public return static super switch this throw true try type typeof undefined var void while with yield');

const LANGS: Record<string, Pattern[]> = {
  php: [['tok-cmt', CMT_SLASH + '|' + CMT_HASH], ['tok-str', STR], ['tok-kw', kw('abstract and array as break callable case catch class clone const continue declare default do echo else elseif empty enddeclare endfor endforeach endif endswitch endwhile enum extends final finally fn for foreach function global goto if implements include include_once instanceof insteadof interface isset list match namespace new null or print private protected public readonly require require_once return static switch throw trait true false try unset use var while xor yield self parent int string bool float void mixed never iterable object')], ['tok-num', NUM], ['tok-fn', FN]],
  js: [['tok-cmt', CMT_SLASH], ['tok-str', STR + '|' + STR_TPL], ['tok-kw', JS_KW], ['tok-num', NUM], ['tok-fn', FN]],
  json: [['tok-kw', String.raw`"(?:\\.|[^"\\\n])*"(?=\s*:)`], ['tok-str', STR], ['tok-num', String.raw`-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?`], ['tok-kw', kw('true false null')]],
  bash: [['tok-cmt', CMT_HASH], ['tok-str', STR], ['tok-kw', kw('if then else elif fi for while until do done case esac in function select return exit export local readonly set unset source alias echo cd ls cat grep sed awk sudo')], ['tok-num', NUM], ['tok-fn', String.raw`\$\{?[\w@#?*-]+\}?`]],
  css: [['tok-cmt', String.raw`\/\*[\s\S]*?\*\/`], ['tok-str', STR], ['tok-kw', String.raw`@[\w-]+|!important`], ['tok-fn', String.raw`[\w-]+(?=\s*\()`], ['tok-num', String.raw`#[\da-fA-F]{3,8}\b|-?\d+(?:\.\d+)?(?:px|em|rem|%|vh|vw|s|ms|deg|fr)?\b`]],
  html: [['tok-cmt', String.raw`<!--[\s\S]*?-->`], ['tok-str', STR], ['tok-kw', String.raw`<\/?[A-Za-z][\w:-]*|\/?>`], ['tok-fn', String.raw`\b[\w:-]+(?==)`]],
  sql: [['tok-cmt', String.raw`--[^\n]*|\/\*[\s\S]*?\*\/`], ['tok-str', STR], ['tok-kw', kw('SELECT FROM WHERE AND OR NOT IN IS NULL AS JOIN LEFT RIGHT INNER OUTER ON GROUP BY ORDER HAVING LIMIT OFFSET INSERT INTO VALUES UPDATE SET DELETE CREATE TABLE ALTER DROP INDEX PRIMARY KEY DEFAULT UNIQUE DISTINCT UNION ALL CASE WHEN THEN ELSE END LIKE BETWEEN EXISTS TRUE FALSE select from where and or not in is null as join left right inner outer on group by order having limit offset insert into values update set delete create table alter drop index primary key default unique distinct union all case when then else end like between exists true false')], ['tok-num', NUM], ['tok-fn', FN]],
  python: [['tok-cmt', CMT_HASH], ['tok-str', String.raw`"""[\s\S]*?"""|'''[\s\S]*?'''|` + STR], ['tok-kw', kw('False None True and as assert async await break class continue def del elif else except finally for from global if import in is lambda nonlocal not or pass raise return try while with yield self print')], ['tok-num', NUM], ['tok-fn', FN]],
};
const ALIASES: Record<string, string> = { javascript: 'js', jsx: 'js', typescript: 'js', ts: 'js', tsx: 'js', sh: 'bash', shell: 'bash', zsh: 'bash', console: 'bash', py: 'python', xml: 'html', mysql: 'sql' };
const compiled = new Map<string, { classes: string[]; re: RegExp }>();

export function escapeHtml(s: string): string {
  return s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c] as string);
}

export function highlight(code: string, lang: string): string {
  const name = lang.toLowerCase();
  const table = LANGS[ALIASES[name] ?? name];
  if (!table) return escapeHtml(code);
  let c = compiled.get(name);
  if (!c) {
    c = { classes: table.map((p) => p[0]), re: new RegExp(table.map((p) => '(' + p[1] + ')').join('|'), 'g') };
    compiled.set(name, c);
  }
  let out = '';
  let last = 0;
  for (const m of code.matchAll(c.re)) {
    const i = m.slice(1).findIndex((g) => g !== undefined);
    out += escapeHtml(code.slice(last, m.index)) + `<span class="${c.classes[i]}">` + escapeHtml(m[0]) + '</span>';
    last = m.index + m[0].length;
  }
  return out + escapeHtml(code.slice(last));
}

/** Highlights every fenced block under `root` once and gives it a copy button (Kanboard #613). */
export function decorate(root: ParentNode, copyLabel = 'Copy code'): void {
  for (const code of root.querySelectorAll<HTMLElement>('pre > code[class*="language-"]')) {
    const pre = code.parentElement as HTMLElement;
    if (pre.dataset.highlighted) continue;
    pre.dataset.highlighted = '1';
    const lang = /language-([\w-]+)/.exec(code.className)?.[1] ?? '';
    code.innerHTML = highlight(code.textContent ?? '', lang);
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'ab-btn ab-btn--icon ab-code__copy';
    button.dataset.action = 'copy-code';
    button.setAttribute('aria-label', copyLabel);
    button.innerHTML = '<svg class="ab-icon" aria-hidden="true" focusable="false"><use href="#lucide-copy"></use></svg>';
    pre.prepend(button);
  }
}
