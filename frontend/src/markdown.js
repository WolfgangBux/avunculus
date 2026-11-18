/**
 * Kleines, sicheres Markdown für Versionshinweise (GitHub-Releases): Überschriften, Listen, Absätze,
 * **fett**, *kursiv*, `Code`, [Links](https://…) und nackte https-Adressen.
 * Zuerst wird der ganze Text HTML-maskiert, danach entstehen nur diese Elemente – eingeschleustes
 * HTML oder javascript:-Links sind damit ausgeschlossen. Kein vollständiger Markdown-Parser.
 */
const escape = s => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')

function inline(text) {
  const codes = []
  let s = escape(text).replace(/`([^`]+)`/g, (_, c) => `\u0000${codes.push(c) - 1}\u0000`)   // Code nicht weiter formatieren
  s = s
    .replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>')
    .replace(/(^|[\s(])(https?:\/\/[^\s<)]+)/g, '$1<a href="$2" target="_blank" rel="noopener">$2</a>')
    .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
    .replace(/(^|[^*\w])\*([^*\s][^*]*)\*(?!\w)/g, '$1<em>$2</em>')
  return s.replace(/\u0000(\d+)\u0000/g, (_, i) => `<code>${codes[i]}</code>`)
}

export function renderMarkdown(md) {
  const out  = []
  let list   = null   // 'ul' | 'ol' während einer Liste
  let para   = []
  const flushPara = () => { if (para.length) { out.push(`<p>${inline(para.join(' '))}</p>`); para = [] } }
  const closeList = () => { if (list) { out.push(`</${list}>`); list = null } }

  for (const raw of String(md ?? '').replace(/\r\n?/g, '\n').split('\n')) {
    const line = raw.trimEnd()
    let m
    if (!line.trim()) { flushPara(); closeList(); continue }
    if ((m = line.match(/^(#{1,6})\s+(.*)$/))) {
      flushPara(); closeList()
      const level = Math.min(6, m[1].length + 2)   // ## im Release → h4, passt in einen Dialog
      out.push(`<h${level}>${inline(m[2])}</h${level}>`)
    } else if ((m = line.match(/^\s*([-*+]|\d+[.)])\s+(.*)$/))) {
      flushPara()
      const type = /\d/.test(m[1]) ? 'ol' : 'ul'
      if (list !== type) { closeList(); out.push(`<${type}>`); list = type }
      out.push(`<li>${inline(m[2])}</li>`)
    } else {
      closeList()
      para.push(line.trim())
    }
  }
  flushPara(); closeList()
  return out.join('')
}
