import { reactive } from 'vue'
import { currentTreeId } from '../trees.js'

/**
 * Ansicht der Personenliste je Baum: Liste oder Nachnamen-Übersicht, gewählter Buchstabe und Nachname,
 * bei einem Nachnamen Personen oder Zweige. Liegt außerhalb der Komponente, damit sie den Wechsel in
 * andere Tabs übersteht (die Liste wird dabei neu aufgebaut) und andere Ansichten sie setzen können.
 * mode null = noch nicht entschieden (PersonList wählt nach Größe des Baums).
 * query: Suchtext, den die Liste beim nächsten Aufbau übernimmt (dann null).
 */
export const personListNav = reactive({ tree: null, mode: null, letter: null, surname: null, show: 'persons', query: null })

/** Personenliste auf diesen Nachnamen stellen ('' = ohne Nachnamen) – danach in den Tab „Personen“ wechseln */
export function showSurnameInList(surname) {
  Object.assign(personListNav, { tree: currentTreeId.value, mode: 'surnames', letter: null, surname, show: 'persons', query: '' })
}

/** Personenliste mit dieser Suche öffnen (z. B. ein Vorname aus der Wolke) – danach in den Tab „Personen“ wechseln */
export function showSearchInList(query) {
  Object.assign(personListNav, { tree: currentTreeId.value, mode: 'list', letter: null, surname: null, show: 'persons', query })
}
