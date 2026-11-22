import { request } from './http.js'

export const fetchPerson    = (id)        => request('GET',    `/persons/${id}`)
export const createPerson   = (data)      => request('POST',   '/persons', data)
export const updatePerson   = (id, data)  => request('PUT',    `/persons/${id}`, data)
export const deletePerson   = (id)        => request('DELETE', `/persons/${id}`)

/**
 * Serverseitige Suche: { total, items } – q: Namen und/oder Jahreszahl, exclude: Personen-IDs,
 * surname: nur Personen mit diesem Nachnamen ('' = ohne Nachnamen, null = alle)
 */
export function searchPersons(q = '', { limit = 50, offset = 0, exclude = [], around = null, surname = null } = {}) {
  const params = new URLSearchParams({ q, limit: String(limit), offset: String(offset) })
  if (surname != null) params.set('surname', surname)
  if (exclude.length) params.set('exclude', exclude.join(','))
  if (around) params.set('around', String(around))   // Seite, auf der diese Person steht
  return request('GET', `/persons/search?${params}`)
}
export const fetchPersonPlaces = (id, ancestors = 0) => request('GET', `/persons/${id}/places` + (ancestors ? `?ancestors=${ancestors}` : ''))
