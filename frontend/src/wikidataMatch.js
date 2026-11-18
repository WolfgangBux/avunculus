/**
 * Orte automatisch mit Wikidata abgleichen.
 *
 * Vorgehen von oben nach unten entlang der Ortshierarchie:
 *  1. Oberste Orte ohne Wikidata-Kennung werden unter Staaten gesucht (Deutschland, Italien …).
 *  2. Jeder Ort, dessen Oberort eine Kennung hat (gespeichert oder in diesem Lauf sicher gefunden),
 *     wird unter den Objekten gesucht, die – auch über Zwischenstufen – in diesem Oberort liegen
 *     (Wikidata P131 „liegt in der Verwaltungseinheit“). Nur Siedlungen und Verwaltungseinheiten zählen,
 *     damit z. B. der Fluss „Regen“ nicht für die Stadt Regen gehalten wird.
 *  3. Liegt der beste Treffer nicht direkt im Oberort, wird zusätzlich mit „Landkreis …“, „Gemeinde …“
 *     usw. gesucht – „Kelheim, Niederbayern“ ist meist der Landkreis, nicht die Stadt.
 *  4. Ohne jeden Treffer wird unter dem nächsthöheren Ort gesucht (bei Wikidata fehlt oft eine
 *     Zwischenebene, z. B. Weiler direkt in der Gemeinde statt im Ortsteil) – aber nie auf Landes-
 *     oder Staatsebene, das wäre zu ungenau und für den Abfragedienst zu teuer.
 *
 * Schlägt eine Abfrage trotz Wiederholung fehl, bleiben deren Orte ungeprüft (stats.failed) –
 * der nächste Lauf holt sie nach.
 *
 * Bewertung je Kandidat: direkt im Oberort +4, Name ohne Zusatz +2, Bezeichnung statt Alias +1,
 * hat GOV-Kennung +1 (genealogisch relevanter Ort), „Hauptort der Stadt …“ −1, „früherer/ehemaliger …“ −1.
 * Treffer unter WEAK gelten als eindeutig, sollten aber geprüft werden (nur indirekt gefunden).
 * Genau ein bester Kandidat → „sicher“, mehrere gleich gute → „mehrdeutig“ (Nutzer entscheidet).
 * Unter mehrdeutigen oder nicht gefundenen Orten wird nicht weitergesucht.
 */

const ENDPOINT = 'https://query.wikidata.org/sparql'

const SETTLEMENT_OR_ADMIN = 'wd:Q486972 wd:Q56061'                 // menschliche Siedlung, Verwaltungseinheit
const STATE_CLASSES       = 'wd:Q6256 wd:Q3024240 wd:Q7275 wd:Q1250464'   // Staat, historischer Staat, Staat (polit.), Realm
const PREFIXES = ['Landkreis', 'Kreis', 'Regierungsbezirk', 'Bezirk', 'Gemeinde', 'Stadt', 'Markt', 'Amt']

const BATCH_PLAIN    = 50   // Orte je Abfrage (ein Name je Ort)
const BATCH_PREFIXED = 12   // Orte je Abfrage mit allen Zusätzen
const PARALLEL       = 3    // gleichzeitige Abfragen (der Dienst erlaubt einige wenige je Nutzer)
const STRONG         = 6    // direkt im Oberort und Name ohne Zusatz – keine weitere Suche nötig
export const WEAK    = 5    // darunter: eindeutig, aber nicht direkt im Oberort gefunden

const lit = (s, lang = 'de') => `"${String(s).replace(/\\/g, '\\\\').replace(/"/g, '\\"')}"@${lang}`
const qid = uri => uri.slice(uri.lastIndexOf('/') + 1)
const sleep = ms => new Promise(r => setTimeout(r, ms))

async function sparql(query, signal, attempt = 0) {
  const res = await fetch(ENDPOINT, {
    method:  'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/sparql-results+json' },
    body:    new URLSearchParams({ query }),
    signal,
  })
  // Abfragedienst überlastet: kurz warten und erneut versuchen
  if ((res.status === 429 || res.status >= 500) && attempt < 3) {
    const wait = Number(res.headers.get('Retry-After')) || 5 * (attempt + 1)
    await sleep(wait * 1000)
    return sparql(query, signal, attempt + 1)
  }
  if (!res.ok) throw new Error(`Wikidata-Abfrage fehlgeschlagen (${res.status})`)
  return (await res.json()).results.bindings
}

/** "Point(13.6298 48.7303)" → [lat, lon] */
function point(wkt) {
  const m = wkt?.match(/Point\(([-\d.eE]+) ([-\d.eE]+)\)/)
  return m ? [Number(m[2]), Number(m[1])] : [null, null]
}

const SELECT = `?key ?item ?direct ?exact ?variant
  (SAMPLE(?lab) AS ?label) (SAMPLE(?d) AS ?desc) (SAMPLE(?c) AS ?coord) (SAMPLE(?g) AS ?gov) (SAMPLE(?tl) AS ?type)`
const DETAILS = `
  OPTIONAL { ?item rdfs:label ?lab FILTER(LANG(?lab) = "de") }
  OPTIONAL { ?item schema:description ?d FILTER(LANG(?d) = "de") }
  OPTIONAL { ?item wdt:P625 ?c }
  OPTIONAL { ?item wdt:P2503 ?g }
  OPTIONAL { ?item wdt:P31 ?t . ?t rdfs:label ?tl FILTER(LANG(?tl) = "de") }`

/** Zeilen (key = Orts-ID) → { key: [Kandidat …] } mit Bewertung, je Objekt nur die beste Zeile */
function collect(rows) {
  const out = {}
  for (const r of rows) {
    const key  = r.key.value
    const [lat, lon] = point(r.coord?.value)
    const cand = {
      qid:   qid(r.item.value),
      label: r.label?.value ?? qid(r.item.value),
      desc:  r.desc?.value ?? '',
      type:  r.type?.value ?? null,
      gov:   r.gov?.value ?? null,
      lat, lon,
      score: (r.direct?.value === 'true' ? 4 : 0) + (r.variant?.value === '0' ? 2 : 0) + (r.exact?.value === 'true' ? 1 : 0),
    }
    if (cand.gov) cand.score += 1
    if (/^Hauptort/i.test(cand.type ?? '') || /^Hauptort/i.test(cand.desc)) cand.score -= 1
    if (/^(früher|ehemalig)/i.test(cand.desc)) cand.score -= 1
    const list = (out[key] ??= [])
    const same = list.find(c => c.qid === cand.qid)
    if (!same) list.push(cand)
    else if (cand.score > same.score) Object.assign(same, cand)
  }
  for (const list of Object.values(out)) list.sort((a, b) => b.score - a.score)
  return out
}

async function searchRoots(places, signal) {
  const values = places.flatMap(p => [`("${p.id}" ${lit(p.name)} 0)`, `("${p.id}" ${lit(p.name, 'en')} 0)`]).join(' ')
  return collect(await sparql(`SELECT ${SELECT} WHERE {
    VALUES (?key ?name ?variant) { ${values} }
    ?item rdfs:label|skos:altLabel ?name .
    FILTER EXISTS { ?item wdt:P31/wdt:P279* ?cls . VALUES ?cls { ${STATE_CLASSES} } }
    BIND(true AS ?direct)
    BIND(EXISTS { ?item rdfs:label ?name } AS ?exact)
    ${DETAILS}
  } GROUP BY ?key ?item ?direct ?exact ?variant`, signal))
}

/** mode: 'plain' (Name im Oberort), 'prefixed' (mit „Landkreis …“ usw.), 'up' (Name im übernächsten Ort) */
async function searchChildren(entries, signal, mode) {
  const values = entries.flatMap(({ place, parentQid, upperQid }) => {
    const names = mode === 'prefixed' ? PREFIXES.map(p => `${p} ${place.name}`) : [place.name]
    const under = mode === 'up' ? upperQid : parentQid
    return names.map(n => `("${place.id}" wd:${under} ${lit(n)} ${mode === 'plain' ? 0 : 1})`)
  }).join(' ')
  return collect(await sparql(`SELECT ${SELECT} WHERE {
    VALUES (?key ?parent ?name ?variant) { ${values} }
    ?item rdfs:label|skos:altLabel ?name .
    ?item wdt:P131+ ?parent .
    FILTER EXISTS { ?item wdt:P31/wdt:P279* ?cls . VALUES ?cls { ${SETTLEMENT_OR_ADMIN} } }
    BIND(EXISTS { ?item wdt:P131 ?parent } AS ?direct)
    BIND(EXISTS { ?item rdfs:label ?name } AS ?exact)
    ${DETAILS}
  } GROUP BY ?key ?item ?direct ?exact ?variant`, signal))
}

/** Bester Kandidat eindeutig? */
function judge(cands) {
  if (!cands?.length) return 'none'
  return cands.length === 1 || cands[0].score > cands[1].score ? 'sure' : 'ambiguous'
}

const bestScore = cands => cands?.[0]?.score ?? -Infinity

/** Kandidatenlisten zusammenführen (je Objekt die beste Bewertung), absteigend sortiert */
function mergeCands(a = [], b = []) {
  const map = new Map(a.map(c => [c.qid, c]))
  for (const c of b) if (!map.has(c.qid) || c.score > map.get(c.qid).score) map.set(c.qid, c)
  return [...map.values()].sort((x, y) => y.score - x.score)
}

/** Stapel mit begrenzter Gleichzeitigkeit abarbeiten; fehlgeschlagene Stapel melden statt abbrechen */
async function runBatches(items, size, fn, onFail) {
  const batches = []
  for (let i = 0; i < items.length; i += size) batches.push(items.slice(i, i + size))
  let next = 0
  const worker = async () => {
    while (next < batches.length) {
      const batch = batches[next++]
      try { await fn(batch) }
      catch (e) { if (e.name === 'AbortError') throw e; onFail(batch, e) }
    }
  }
  await Promise.all(Array.from({ length: Math.min(PARALLEL, batches.length) }, worker))
}

/**
 * @param places    Orte wie von GET /api/places (id, parent_id, name, wikidata_id)
 * @param onProgress({ checked, total, sure, ambiguous, none, level })
 * @param results   Map, in die die Ergebnisse geschrieben werden (bleibt bei Abbruch gefüllt)
 * @returns { results: Map placeId → { status: 'sure'|'ambiguous'|'none', candidates }, stats }
 */
export async function matchPlaces(places, { signal, onProgress = () => {}, results = new Map() } = {}) {
  const byId  = new Map(places.map(p => [p.id, p]))
  const known = new Map(places.filter(p => p.wikidata_id).map(p => [p.id, p.wikidata_id]))   // Ort → QID
  const stats = { checked: 0, total: places.filter(p => !p.wikidata_id).length, sure: 0, ambiguous: 0, none: 0, level: 0, failed: 0 }
  const failed = new Set()
  const fail = batch => { batch.forEach(x => failed.add((x.place ?? x).id)); stats.failed += batch.length; onProgress({ ...stats }) }

  const record = (place, cands) => {
    const status = judge(cands)
    results.set(place.id, { status, candidates: cands ?? [] })
    stats[status]++
    stats.checked++
    if (status === 'sure') known.set(place.id, cands[0].qid)
  }

  // 1. oberste Ebene
  const roots = places.filter(p => p.parent_id === null && !p.wikidata_id)
  await runBatches(roots, BATCH_PLAIN, async batch => {
    const found = await searchRoots(batch, signal)
    batch.forEach(p => record(p, found[p.id]))
    onProgress({ ...stats })
  }, fail)

  // 2. Ebene für Ebene abwärts
  for (;;) {
    const todo = places.filter(p => !p.wikidata_id && !results.has(p.id) && !failed.has(p.id)
                                 && p.parent_id !== null && known.has(p.parent_id))
    if (!todo.length) break
    stats.level++
    const cands = new Map()
    const entries = todo.map(place => {
      const parent = byId.get(place.parent_id)
      const upper  = parent?.parent_id ? byId.get(parent.parent_id) : null
      // höher suchen nur, wenn der übernächste Ort selbst noch einen Oberort hat (nicht Land/Staat)
      return { place, parentQid: known.get(place.parent_id),
               upperQid: upper && upper.parent_id !== null ? known.get(upper.id) ?? null : null }
    })

    // a) Name im Oberort – starke Treffer stehen sofort fest
    const weak = []
    await runBatches(entries, BATCH_PLAIN, async batch => {
      const found = await searchChildren(batch, signal, 'plain')
      for (const e of batch) {
        const list = mergeCands([], found[e.place.id])
        if (bestScore(list) >= STRONG && judge(list) === 'sure') record(e.place, list)
        else { cands.set(e.place.id, list); weak.push(e) }
      }
      onProgress({ ...stats })
    }, fail)

    // b) mit Zusätzen („Landkreis Kelheim“)
    const lost = []
    await runBatches(weak, BATCH_PREFIXED, async batch => {
      const found = await searchChildren(batch, signal, 'prefixed')
      for (const e of batch) {
        const list = mergeCands(cands.get(e.place.id), found[e.place.id])
        if (list.length || !e.upperQid) record(e.place, list)
        else lost.push(e)
      }
      onProgress({ ...stats })
    }, fail)

    // c) gar nichts gefunden: eine Ebene höher suchen
    await runBatches(lost, BATCH_PLAIN, async batch => {
      const found = await searchChildren(batch, signal, 'up')
      batch.forEach(e => record(e.place, mergeCands([], found[e.place.id])))
      onProgress({ ...stats })
    }, fail)
  }

  // Orte unter nicht gefundenen/mehrdeutigen Oberorten konnten nicht geprüft werden
  stats.skipped = places.filter(p => !p.wikidata_id && !results.has(p.id)).length
  onProgress({ ...stats })
  return { results, stats }
}
