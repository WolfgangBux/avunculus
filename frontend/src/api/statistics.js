import { request } from './http.js'

/** Statistik des aktuellen Stammbaums */
export const fetchStatistics = () => request('GET', '/statistics')

/** Ortskarte: Orte mit Koordinaten und Ereignisse je Ort, Art und Jahrzehnt */
/** Wolke: die n häufigsten Vornamen (kind 'given', sex 'M'/'F'/null) bzw. Orte (kind 'place'): { cloud: [{ label, count, id?, title? }] } */
export function fetchStatisticsCloud(kind, n, sex = null) {
  const params = new URLSearchParams({ kind, n: String(n) })
  if (sex) params.set('sex', sex)
  return request('GET', `/statistics/cloud?${params}`)
}

export const fetchStatisticsMap = () => request('GET', '/statistics/map')
