import { request } from './http.js'

/** Statistik des aktuellen Stammbaums */
export const fetchStatistics = () => request('GET', '/statistics')

/** Ortskarte: Orte mit Koordinaten und Ereignisse je Ort, Art und Jahrzehnt */
export const fetchStatisticsMap = () => request('GET', '/statistics/map')
