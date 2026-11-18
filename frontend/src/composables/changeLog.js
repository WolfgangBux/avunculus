import { ref } from 'vue'

/**
 * Ein Änderungsprotokoll-Dialog für die ganze App (in App.vue eingebunden).
 * null = geschlossen; sonst Startfilter { type?, id?, label? } – {} zeigt alles.
 */
export const changeLogFilter = ref(null)

export function openChangeLog(filter = {}) {
  changeLogFilter.value = { ...filter }
}
