import { ref } from 'vue'

/** Verwandtschaftsrechner für die ganze App (in App.vue eingebunden) */
export const showRelationship = ref(false)
/** Vorbelegung beim Öffnen: Personen-IDs (oder null) */
export const relationshipStart = ref({ a: null, b: null })

export function openRelationship(a = null, b = null) {
  a = a ? Number(a) : null
  b = b ? Number(b) : null
  relationshipStart.value = { a, b: b === a ? null : b }
  showRelationship.value = true
}
