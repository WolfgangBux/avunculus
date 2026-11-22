/**
 * Wortwolke (Nachnamen, Vornamen, Orte) als Bild: Anordnung, Farben, Export als SVG und PNG – ohne Bibliothek.
 *
 * Die häufigsten Namen (größte Schrift) stehen in der Mitte, die übrigen werden der Reihe nach auf einer
 * Spirale nach außen geschoben, bis sie keinen schon gesetzten Namen berühren. Die Breite misst ein Canvas
 * mit derselben Schrift, die Anordnung ist also für Anzeige, SVG und PNG dieselbe. Ohne Zufall: gleiche
 * Namen ergeben immer dasselbe Bild.
 */

export const FONT = "'Inter', 'Segoe UI', system-ui, -apple-system, 'Helvetica Neue', Arial, sans-serif"

const TARGET_W = 1200   // Zielfläche der Wolke (Einheiten im SVG, Pixel im PNG vor Vergrößerung)
const TARGET_H = 720

export const ASPECT = { min: 1, max: 3, default: TARGET_W / TARGET_H }   // Breite : Höhe des Bilds (Schieber)
const FILL     = 0.42   // so viel der Zielfläche bedecken die Wortkästen (der Rest sind Lücken)
const MARGIN   = 24
const TITLE_H  = 64     // Platz für die Titelzeile
const MIN_SIZE = 11     // kleinste Schrift (Einheiten wie TARGET_W)

const ASCENT  = 0.78    // Anteil der Schriftgröße über bzw. unter der Grundlinie (Kasten für Überschneidungen)
const DESCENT = 0.24

const weightOf = w => (w > 0.6 ? 700 : w > 0.25 ? 600 : 500)

let measureCtx = null
function measure(text, size, weight) {
  measureCtx ??= document.createElement('canvas').getContext('2d')
  measureCtx.font = `${weight} ${size}px ${FONT}`
  return measureCtx.measureText(text).width
}

export const ORDERS = ['cloud', 'alpha']

/**
 * words: [{ label, count, id?, title? }] (beliebige Reihenfolge; id/title reisen mit, z. B. Ort und ganze Ortsangabe).
 * order: 'cloud' – häufigste in der Mitte, die übrigen spiralförmig darum;
 *        'alpha' – alphabetisch in zentrierten Zeilen (die Größe zeigt weiter die Häufigkeit)
 * aspect: gewünschtes Verhältnis Breite : Höhe (ungefähr – die Wörter bestimmen den genauen Umriss)
 * @returns {{ items: Array<{text, count, weight, size, fontWeight, x, y, w, h}>, box: {x, y, w, h} }}
 *   x/y = Anfang der Grundlinie; weight 0…1 (logarithmisch nach Anzahl), box = Umriss aller Wörter
 */
export function layoutCloud(words, order = 'cloud', aspect = ASPECT.default) {
  if (!words.length) return { items: [], box: { x: 0, y: 0, w: TARGET_W, h: TARGET_H } }
  const items = sized(words)
  return bounds(order === 'alpha' ? flowAlpha(items, aspect) : spiral(items, aspect))
}

/** Wörter nach Häufigkeit (häufigste zuerst) mit Schriftgröße, Gewicht und gemessener Breite */
function sized(words) {
  const sorted = [...words].sort((a, b) => b.count - a.count || a.label.localeCompare(b.label))
  const logs   = sorted.map(s => Math.log(s.count))
  const lo = Math.min(...logs), span = Math.max(...logs) - lo || 1

  // Schriftgrößen erst im Verhältnis 1 : 4, dann so skaliert, dass die Wörter die Zielfläche füllen
  let items = sorted.map((s, i) => {
    const weight = (logs[i] - lo) / span
    const size = 14 + 42 * weight
    const fontWeight = weightOf(weight)
    return { text: s.label, id: s.id ?? null, title: s.title ?? null, count: s.count, weight, size, fontWeight, w: measure(s.label, size, fontWeight) }
  })
  const area = items.reduce((a, it) => a + it.w * it.size * (ASCENT + DESCENT), 0)
  // Kleinste Schrift nie unter MIN_SIZE (bei 300 Namen sonst unlesbar) – die Wolke wird dann eben größer
  const k = Math.max(MIN_SIZE / 14, Math.min(3, Math.sqrt((FILL * TARGET_W * TARGET_H) / area)))
  return items.map(it => ({ ...it, size: it.size * k, w: it.w * k, h: it.size * k * (ASCENT + DESCENT) }))
}

/** Alphabetisch in Zeilen, jede Zeile zentriert; Zeilenbreite so, dass das Ganze etwa im Querformat liegt */
function flowAlpha(items, aspect) {
  const collator = new Intl.Collator(undefined, { sensitivity: 'base' })
  const list  = [...items].sort((a, b) => collator.compare(a.text, b.text))
  const gap   = it => it.size * 0.35
  const area  = list.reduce((a, it) => a + (it.w + gap(it)) * it.h * 1.25, 0)
  // Zeilen sind höher als die Wortkästen (Abstand, größtes Wort bestimmt die Zeile) → etwas breiter ansetzen
  const width = Math.max(1.3 * Math.sqrt(area * aspect), ...list.map(it => it.w))

  const lines = [[]]
  let used = 0
  for (const it of list) {
    const need = it.w + (lines.at(-1).length ? gap(it) : 0)
    if (used + need > width && lines.at(-1).length) { lines.push([]); used = 0 }
    used += lines.at(-1).length ? it.w + gap(it) : it.w
    lines.at(-1).push(it)
  }

  const placed = []
  let top = 0
  for (const line of lines) {
    const big  = Math.max(...line.map(it => it.size))
    const base = top + big * ASCENT
    const lineW = line.reduce((a, it, i) => a + it.w + (i ? gap(it) : 0), 0)
    let x = (width - lineW) / 2
    line.forEach((it, i) => {
      if (i) x += gap(it)
      placed.push({ ...it, x, y: base })
      x += it.w
    })
    top += big * (ASCENT + DESCENT) + big * 0.12 + 4
  }
  return placed
}

/** Häufigste in der Mitte, die übrigen der Reihe nach auf einer Spirale nach außen */
function spiral(items, aspect) {
  // Belegte Kästen in einem Raster, damit jeder Versuch nur die Nachbarschaft prüft
  const CELL = 48
  const grid = new Map()
  const cells = (r, fn) => {
    for (let cx = Math.floor(r.x / CELL); cx <= Math.floor((r.x + r.w) / CELL); cx++)
      for (let cy = Math.floor(r.y / CELL); cy <= Math.floor((r.y + r.h) / CELL); cy++)
        if (fn(cx + ',' + cy) === false) return false
    return true
  }
  const free = r => cells(r, key => !(grid.get(key) ?? []).some(o =>
    r.x < o.x + o.w && o.x < r.x + r.w && r.y < o.y + o.h && o.y < r.y + r.h))
  const occupy = r => cells(r, key => { (grid.get(key) ?? grid.set(key, []).get(key)).push(r) })

  const ratio = aspect
  const placed = []
  for (const it of items) {
    const pad = 2 + it.size * 0.06
    const w = it.w + 2 * pad, h = it.h + 2 * pad
    // Archimedische Spirale, in die Breite gezogen; großer Schritt am Anfang wäre ungenau, daher fein
    for (let theta = 0, step = 0; step < 40000; step++, theta += 0.12) {
      const r = 1.6 * theta
      const rect = { x: ratio * r * Math.cos(theta) - w / 2, y: r * Math.sin(theta) - h / 2, w, h }
      if (free(rect)) {
        occupy(rect)
        placed.push({ ...it, x: rect.x + pad, y: rect.y + pad + it.size * ASCENT })
        break
      }
    }
  }
  return placed
}

/** Umriss aller gesetzten Wörter */
function bounds(placed) {
  let x0 = Infinity, y0 = Infinity, x1 = -Infinity, y1 = -Infinity
  for (const it of placed) {
    x0 = Math.min(x0, it.x); x1 = Math.max(x1, it.x + it.w)
    y0 = Math.min(y0, it.y - it.size * ASCENT); y1 = Math.max(y1, it.y + it.size * DESCENT)
  }
  return { items: placed, box: { x: x0, y: y0, w: x1 - x0, h: y1 - y0 } }
}

// ── Farben ──────────────────────────────────────────────

export const SCHEMES = ['orange', 'colorful', 'mono']

const ORANGE_LIGHT = [222, 140, 82]    // seltene Namen (noch lesbar auf Weiß)
const ORANGE_DARK  = [140, 50, 6]      // häufigste
const COLORFUL = ['#e8641b', '#3b7dd8', '#2e9e5b', '#8e5cc8', '#c9520f', '#c2477f', '#2b8a99', '#7a6a12']

export function wordColor(item, index, scheme) {
  if (scheme === 'mono') {
    const v = Math.round(120 - 95 * item.weight)   // seltene grau, häufige fast schwarz
    return `rgb(${v}, ${v + 4}, ${v + 10})`
  }
  if (scheme === 'colorful') return COLORFUL[index % COLORFUL.length]
  const c = ORANGE_LIGHT.map((l, i) => Math.round(l + (ORANGE_DARK[i] - l) * item.weight))
  return `rgb(${c.join(', ')})`
}

// ── Bildrahmen (Wolke + optionaler Titel) ──────────────

/** Ausschnitt des fertigen Bilds: viewBox-Werte und Lage des Titels */
export function frame(layout, title) {
  const { box } = layout
  const top = title ? TITLE_H : 0
  const w   = Math.max(box.w + 2 * MARGIN, title ? measure(title, 30, 700) + 2 * MARGIN : 0)   // langer Titel: breiter
  return {
    x: box.x + box.w / 2 - w / 2, y: box.y - MARGIN - top, w,
    h: box.h + 2 * MARGIN + top,
    titleX: box.x + box.w / 2, titleY: box.y - MARGIN - top + MARGIN + 30,
  }
}

// ── Export ──────────────────────────────────────────────

const esc = s => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')
const r1 = n => Math.round(n * 10) / 10

/** Eigenständige SVG-Datei (Schrift als Name: auf anderen Rechnern kann eine ähnliche einspringen) */
export function cloudSvg(layout, scheme, title) {
  const f = frame(layout, title)
  const words = layout.items.map((it, i) =>
    `<text x="${r1(it.x)}" y="${r1(it.y)}" font-size="${r1(it.size)}" font-weight="${it.fontWeight}" fill="${wordColor(it, i, scheme)}">${esc(it.text)}</text>`)
  return `<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" viewBox="${r1(f.x)} ${r1(f.y)} ${r1(f.w)} ${r1(f.h)}" width="${Math.round(f.w)}" height="${Math.round(f.h)}" font-family="${esc(FONT)}">
<rect x="${r1(f.x)}" y="${r1(f.y)}" width="${r1(f.w)}" height="${r1(f.h)}" fill="#ffffff"/>
${title ? `<text x="${r1(f.titleX)}" y="${r1(f.titleY)}" font-size="30" font-weight="700" fill="#1f2630" text-anchor="middle">${esc(title)}</text>\n` : ''}${words.join('\n')}
</svg>
`
}

/** PNG in scale-facher Größe (Standard 2: scharf auf hochauflösenden Bildschirmen und im Druck) */
export function cloudPng(layout, scheme, title, scale = 2) {
  const f = frame(layout, title)
  const canvas = document.createElement('canvas')
  canvas.width  = Math.round(f.w * scale)
  canvas.height = Math.round(f.h * scale)
  const ctx = canvas.getContext('2d')
  ctx.fillStyle = '#ffffff'
  ctx.fillRect(0, 0, canvas.width, canvas.height)
  ctx.setTransform(scale, 0, 0, scale, -f.x * scale, -f.y * scale)
  ctx.textBaseline = 'alphabetic'
  if (title) {
    ctx.font = `700 30px ${FONT}`
    ctx.fillStyle = '#1f2630'
    ctx.textAlign = 'center'
    ctx.fillText(title, f.titleX, f.titleY)
    ctx.textAlign = 'left'
  }
  layout.items.forEach((it, i) => {
    ctx.font = `${it.fontWeight} ${it.size}px ${FONT}`
    ctx.fillStyle = wordColor(it, i, scheme)
    ctx.fillText(it.text, it.x, it.y)
  })
  return new Promise(resolve => canvas.toBlob(resolve, 'image/png'))
}

/** Datei im Browser speichern */
export function saveBlob(blob, filename) {
  const url = URL.createObjectURL(blob)
  const a = Object.assign(document.createElement('a'), { href: url, download: filename })
  document.body.appendChild(a)
  a.click()
  a.remove()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}
