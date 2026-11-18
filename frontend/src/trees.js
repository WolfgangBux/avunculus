// Aktuell geöffneter Stammbaum – wird bei jedem API-Aufruf als X-Tree-Id mitgeschickt
import { ref } from 'vue'

const KEY = 'avunculus.tree'

export const currentTreeId = ref(null)

export function setCurrentTree(id) {
  currentTreeId.value = id
  try { if (id) localStorage.setItem(KEY, String(id)) } catch { /* kein Storage */ }
}

export function storedTreeId() {
  try { return Number(localStorage.getItem(KEY)) || null } catch { return null }
}
