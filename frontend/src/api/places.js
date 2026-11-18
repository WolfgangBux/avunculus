import { request } from './http.js'

export const fetchPlaces  = ()               => request('GET',    '/places')
export const fetchPlace   = (id)             => request('GET',    `/places/${id}`)
export const createPlace  = (data)           => request('POST',   '/places', data)
export const updatePlace  = (id, data)       => request('PUT',    `/places/${id}`, data)
export const mergePlace   = (id, targetId)   => request('POST',   `/places/${id}/merge`, { target_id: targetId })
export const deletePlace  = (id)             => request('DELETE', `/places/${id}`)
export const deleteUnusedPlaces = ()          => request('POST',   '/places/delete-unused')
export const applyWikidataMatches = (items) => request('POST', '/places/wikidata', { items })
export const searchPlaces = (q, limit = 20)  =>
  request('GET', `/places/search?q=${encodeURIComponent(q)}&limit=${limit}`)

// ── Wikidata (die API erlaubt Abrufe aus dem Browser mit origin=*) ──────────

const WD_API = 'https://www.wikidata.org/w/api.php?format=json&origin=*&'
/** Sprachen, deren Bezeichnungen als andere Namen angeboten werden */
const WD_LANGS = ['de', 'en', 'cs', 'pl', 'la', 'sk', 'hu', 'sl', 'it', 'fr', 'nl']

async function wd(params) {
  const res = await fetch(WD_API + new URLSearchParams(params))
  if (!res.ok) throw new Error('wikidata')
  return res.json()
}

/** Objekte suchen: [{ id, label, description }] */
export async function searchWikidata(query, lang = 'de') {
  const data = await wd({ action: 'wbsearchentities', search: query, language: lang, uselang: lang, type: 'item', limit: 20 })
  return (data.search ?? []).map(r => ({ id: r.id, label: r.label ?? r.id, description: r.description ?? '' }))
}

/**
 * Angaben eines Objekts: Bezeichnung, Koordinaten (P625), GOV-Kennung (P2503),
 * Art (P31, z. B. „Stadt in Deutschland“) und Bezeichnungen in anderen Sprachen.
 */
export async function fetchWikidataPlace(qid) {
  const data   = await wd({ action: 'wbgetentities', ids: qid, props: 'claims|labels', languages: WD_LANGS.join('|') })
  const entity = data.entities?.[qid]
  if (!entity || entity.missing !== undefined) throw new Error('missing')
  const claim = p => entity.claims?.[p]?.find(c => c.rank === 'preferred') ?? entity.claims?.[p]?.[0]
  const coord = claim('P625')?.mainsnak?.datavalue?.value

  let type = null
  const typeId = claim('P31')?.mainsnak?.datavalue?.value?.id
  if (typeId) {
    try {
      const td = await wd({ action: 'wbgetentities', ids: typeId, props: 'labels', languages: 'de|en' })
      type = td.entities?.[typeId]?.labels?.de?.value ?? td.entities?.[typeId]?.labels?.en?.value ?? null
    } catch { /* Art ist entbehrlich */ }
  }

  return {
    id:        qid,
    label:     entity.labels?.de?.value ?? entity.labels?.en?.value ?? qid,
    latitude:  coord?.latitude  ?? null,
    longitude: coord?.longitude ?? null,
    gov_id:    claim('P2503')?.mainsnak?.datavalue?.value ?? null,
    type,
    labels:    WD_LANGS.filter(l => entity.labels?.[l]).map(l => ({ language: l, name: entity.labels[l].value })),
  }
}
