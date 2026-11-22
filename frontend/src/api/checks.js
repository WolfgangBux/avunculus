import { request } from './http.js'

/**
 * Fehlersuche im aktuellen Baum (nur Bearbeiter):
 * { items: [{ check, person, other?, family?, age?, days?, dates: [eigenes, Vergleich] }], counts, truncated, persons }
 */
export const fetchChecks = () => request('GET', '/checks')

/** Reihenfolge wie im Backend (Plausibility::CHECKS) */
export const CHECKS = [
  'death_before_birth', 'baptism_before_birth', 'burial_before_death', 'too_old',
  'parent_born_after_child', 'mother_too_young', 'mother_too_old', 'father_too_young', 'father_too_old',
  'born_after_mother_death', 'born_after_father_death', 'siblings_too_close',
  'marriage_before_birth', 'marriage_too_young', 'marriage_after_death',
]
