import { currentTreeId } from '../trees.js'

/**
 * Adresse eines API-Pfads, relativ zur Seite – so läuft Avunculus auch in einem Unterordner
 * (example.com/ahnen/). Die App navigiert nur über #…, die Seite liegt also immer im Hauptordner.
 */
export const apiUrl = path => 'api' + path

/** JSON-Request an die PHP-API; hängt den aktuellen Stammbaum als Header an. */
export async function request(method, path, data = null) {
  const headers = { 'Content-Type': 'application/json' }
  if (currentTreeId.value) headers['X-Tree-Id'] = String(currentTreeId.value)

  const res = await fetch(apiUrl(path), {
    method,
    headers,
    body: data ? JSON.stringify(data) : null,
  })

  if (res.status === 204) return null
  // Sitzung abgelaufen o. Ä. – die App lädt den Anmeldestatus neu
  if (res.status === 401 && !path.startsWith('/auth/')) window.dispatchEvent(new Event('avunculus:unauthorized'))
  const json = await res.json().catch(() => ({ error: `HTTP ${res.status}` }))
  if (!res.ok) throw json
  return json
}
