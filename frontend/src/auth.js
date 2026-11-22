// Angemeldeter Benutzer – steuert, was die Oberfläche anbietet (der Server prüft selbst noch einmal)
//   admin  – alles, auch Benutzer und Stammbäume verwalten
//   editor – Daten bearbeiten, exportieren
//   null   – Besucher: nur öffentliche Bäume, nur lesend, Lebende anonymisiert
import { ref, computed } from 'vue'
import { request } from './api/http.js'

export const user       = ref(null)
export const needsSetup = ref(false)   // noch kein Benutzer angelegt → ersten Admin einrichten
export const appVersion = ref('')      // installierte Version (nur für Angemeldete)
export const legalLinks = ref({})      // Impressum/Datenschutz des Betreibers: { imprint_url?, privacy_url? }

export const isAdmin = computed(() => user.value?.role === 'admin')
export const canEdit = computed(() => ['admin', 'editor'].includes(user.value?.role))

function apply(res) {
  user.value       = res?.user ?? null
  needsSetup.value = !!res?.setup
  if (res?.version) appVersion.value = res.version
  if (res?.legal)   legalLinks.value = res.legal
  return user.value
}

export const loadMe = ()                 => request('GET',  '/auth/me').then(apply).catch(() => apply(null))
export const login  = (username, password) => request('POST', '/auth/login', { username, password }).then(apply)
export const logout = ()                 => request('POST', '/auth/logout').then(apply)
export const setup  = (data)             => request('POST', '/auth/setup', data).then(apply)
export const changePassword = (current, password) => request('PUT', '/auth/password', { current, password })

/** Anzeigename des Benutzers */
export const userLabel = u => u?.display_name || u?.username || ''
