import { ref, computed } from 'vue'
import { fetchUpdateStatus } from '../api/system.js'

/** Update-Hinweis für Admins: einmal nach dem Anmelden prüfen, Dialog über showUpdate */
export const updateStatus    = ref(null)
export const showUpdate      = ref(false)
export const updateAvailable = computed(() => !!updateStatus.value?.newer)

export async function checkUpdates(refresh = false) {
  try   { updateStatus.value = await fetchUpdateStatus(refresh) }
  catch { /* kein Netz o. ä. – der Hinweis ist nur Zusatz */ }
  return updateStatus.value
}
