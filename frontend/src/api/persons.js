import { request } from './http.js'

export const fetchPerson    = (id)        => request('GET',    `/persons/${id}`)
export const createPerson   = (data)      => request('POST',   '/persons', data)
export const updatePerson   = (id, data)  => request('PUT',    `/persons/${id}`, data)
export const deletePerson   = (id)        => request('DELETE', `/persons/${id}`)

/** Serverseitige Suche: { total, items } – q: Namen und/oder Jahreszahl, exclude: Personen-IDs */
export function searchPersons(q = '', { limit = 50, offset = 0, exclude = [] } = {}) {
  const params = new URLSearchParams({ q, limit: String(limit), offset: String(offset) })
  if (exclude.length) params.set('exclude', exclude.join(','))
  return request('GET', `/persons/search?${params}`)
}
export const fetchPersonPlaces = (id) => request('GET',    `/persons/${id}/places`)
