import { request } from './http.js'

/** Anfangsbuchstaben mit Anzahl Personen, Anzahl ohne Nachnamen, häufigste Namen: { initials, none, cloud } */
export const fetchSurnameIndex = () => request('GET', '/surnames')

/** Nachnamen eines Anfangsbuchstabens ('#' = kein Buchstabe): { surnames: [{ surname, count }] } */
export const fetchSurnamesByLetter = letter => request('GET', `/surnames?letter=${encodeURIComponent(letter)}`)

/** Zweige eines Nachnamens: { roots, persons, families, bearers } (siehe SurnameController::branches) */
export const fetchSurnameBranches = surname => request('GET', `/surnames/branches?surname=${encodeURIComponent(surname)}`)

/** Die n häufigsten Nachnamen (höchstens 300), häufigste zuerst: { cloud: [{ surname, count }] } */
export const fetchSurnameCloud = n => request('GET', `/surnames?cloud=${n}`)
