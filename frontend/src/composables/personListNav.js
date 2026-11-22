import { reactive } from 'vue'
import { currentTreeId } from '../trees.js'

/**
 * Ansicht der Personenliste je Baum: Liste oder Nachnamen-Übersicht, gewählter Buchstabe und Nachname,
 * bei einem Nachnamen Personen oder Zweige. Liegt außerhalb der Komponente, damit sie den Wechsel in
 * andere Tabs übersteht (die Liste wird dabei neu aufgebaut) und andere Ansichten sie setzen können.
 * mode null = noch nicht entschieden (PersonList wählt nach Größe des Baums).
 */
export const personListNav = reactive({ tree: null, mode: null, letter: null, surname: null, show: 'persons' })

/** Personenliste auf diesen Nachnamen stellen ('' = ohne Nachnamen) – danach in den Tab „Personen“ wechseln */
export function showSurnameInList(surname) {
  Object.assign(personListNav, { tree: currentTreeId.value, mode: 'surnames', letter: null, surname, show: 'persons' })
}
