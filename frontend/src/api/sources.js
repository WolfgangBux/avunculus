import { request } from './http.js'

export const fetchSource   = (id)        => request('GET',    `/sources/${id}`)
export const createSource  = (data)      => request('POST',   '/sources', data)
export const updateSource  = (id, data)  => request('PUT',    `/sources/${id}`, data)
export const deleteSource  = (id)        => request('DELETE', `/sources/${id}`)

/** Serverseitige Suche: { total, items } – jedes Wort in Titel, Kurztitel, Autor, Archiv oder Signatur */
export function searchSources(q = '', { limit = 50, offset = 0 } = {}) {
  const params = new URLSearchParams({ q, limit: String(limit), offset: String(offset) })
  return request('GET', `/sources/search?${params}`)
}

/** Anzeigename einer Quelle in Quellenangaben: Kurztitel, sonst Titel */
export const sourceLabel = s => s?.abbreviation || s?.title || ''
