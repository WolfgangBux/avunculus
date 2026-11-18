import { request } from './http.js'

export const fetchPersonFamilies = (personId)           => request('GET',    `/persons/${personId}/families`)
export const createFamily        = (data)               => request('POST',   '/families', data)
export const addFamilyMember     = (familyId, data)     => request('POST',   `/families/${familyId}/members`, data)
export const removeFamilyMember  = (familyId, personId, { keep = false } = {}) =>
  request('DELETE', `/families/${familyId}/members/${personId}${keep ? '?keep=1' : ''}`)
export const fetchFamily         = (familyId)           => request('GET',    `/families/${familyId}`)
export const updateFamilyMember  = (familyId, personId, data) => request('PUT', `/families/${familyId}/members/${personId}`, data)
export const updateFamily        = (familyId, data)     => request('PUT',    `/families/${familyId}`, data)
export const deleteFamily        = (familyId)           => request('DELETE', `/families/${familyId}`)
export const addFamilyEvent      = (familyId, data)          => request('POST',   `/families/${familyId}/events`, data)
export const updateFamilyEvent   = (familyId, eventId, data) => request('PUT',    `/families/${familyId}/events/${eventId}`, data)
export const deleteFamilyEvent   = (familyId, eventId)       => request('DELETE', `/families/${familyId}/events/${eventId}`)

/** Serverseitige Familienliste: { total, families, persons } */
export function searchFamilies(q = '', { limit = 50, offset = 0 } = {}) {
  const params = new URLSearchParams({ q, limit: String(limit), offset: String(offset) })
  return request('GET', `/families/search?${params}`)
}

/** Ausschnitt für die Stammbaum-Ansicht: { root, total, persons, families } */
export function fetchPedigree(rootId, generations) {
  const params = new URLSearchParams({ generations: String(generations) })
  if (rootId) params.set('root', String(rootId))
  return request('GET', `/pedigree?${params}`)
}
