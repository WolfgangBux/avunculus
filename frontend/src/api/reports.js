import { request } from './http.js'

const query = params => new URLSearchParams(Object.entries(params).filter(([, v]) => v !== '' && v != null)).toString()

/** Ahnentafel: { root, generations, entries: [{ n, gen, person_id, same_as? }], marriages: { n: { date, place } }, persons } */
export const fetchAncestorReport   = (person, generations) => request('GET', `/reports/ancestors?${query({ person, generations })}`)
/** Nachkommen: { root, generations, unions: { person: [{ family_id, partner_id, marriage, children }] }, persons, truncated } */
export const fetchDescendantReport = (person, generations) => request('GET', `/reports/descendants?${query({ person, generations })}`)
/** Familienblatt: { id, partners, children, events, parents, spouses, persons } */
export const fetchFamilyReport     = id => request('GET', `/reports/family/${id}`)
/** Liste: { kind, rows, truncated, limit, persons } – kind: births, baptisms, deaths, burials, marriages, occupations */
export const fetchListReport       = (kind, { from, to, place } = {}) => request('GET', `/reports/list?${query({ kind, from, to, place })}`)
