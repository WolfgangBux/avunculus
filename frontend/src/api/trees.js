import { request, apiUrl } from './http.js'

export const fetchTrees = ()          => request('GET',    '/trees')
export const createTree = (name)      => request('POST',   '/trees', { name })
export const renameTree = (id, name)  => request('PUT',    `/trees/${id}`, { name })
export const deleteTree = (id)        => request('DELETE', `/trees/${id}`)
/** Für Besucher freigeben (nur lesend, Lebende anonymisiert) bzw. zurückziehen */
export const setTreePublic = (id, isPublic) => request('PUT', `/trees/${id}`, { is_public: isPublic })
/** Startperson des Baums festlegen (null = keine) */
export const setTreeStartPerson = (id, personId) => request('PUT', `/trees/${id}`, { start_person_id: personId })

/** Vorschau: Blutsverwandte, Partner, Familien für einen Teilbaum ab einer Person */
export const relativesPreview = (treeId, personId) => request('GET', `/trees/${treeId}/relatives?person=${personId}`)
/** Neuer Stammbaum mit allen Verwandten der Person und deren Partnern */
export const createRelativesTree = (treeId, personId, name) =>
  request('POST', `/trees/${treeId}/relatives`, { person_id: personId, name })

/** Download-Adresse für den GEDCOM-Export (5.5.1, UTF-8) */
export const gedcomExportUrl = (id)   => apiUrl(`/trees/${id}/export`)

const CHUNK = 2 * 1024 * 1024   // bleibt sicher unter den PHP-Limits

function randomToken() {
  const bytes = new Uint8Array(16)
  crypto.getRandomValues(bytes)
  return [...bytes].map(b => b.toString(16).padStart(2, '0')).join('')
}

/**
 * GEDCOM-Datei stückweise hochladen.
 *   target = { name }   → als neuen Stammbaum importieren (Ergebnis: Import-Statistik)
 *   target = { treeId } → Dublettenprüfung gegen bestehenden Baum (Ergebnis: Analyse mit upload-Token)
 * onProgress({ uploaded: 0…1, importing }) meldet vor jedem Stück den Stand;
 * importing = true heißt: letztes Stück unterwegs, der Server verarbeitet die Datei.
 * signal (AbortSignal): Abbrechen; wirft dann einen Fehler mit name = 'AbortError'.
 */
export async function uploadGedcom(file, target, onProgress = () => {}, signal = undefined) {
  const token  = randomToken()
  const chunks = Math.max(1, Math.ceil(file.size / CHUNK))

  for (let i = 0; i < chunks; i++) {
    const last   = i === chunks - 1
    const params = new URLSearchParams({ upload: token, chunk: String(i) })
    if (last) {
      params.set('final', '1')
      if (target.treeId) params.set('target', String(target.treeId))
      else               params.set('name', target.name ?? '')
    }

    onProgress({ uploaded: i / chunks, importing: last })
    const res = await fetch(apiUrl(`/trees/import?${params}`), {
      method:  'POST',
      signal,
      headers: { 'Content-Type': 'application/octet-stream' },
      body:    file.slice(i * CHUNK, (i + 1) * CHUNK),
    })
    const json = await res.json().catch(() => ({ error: `HTTP ${res.status}` }))
    if (!res.ok) throw json
    if (last) return json
  }
}

/** Analysierte Datei mit den Dubletten-Entscheidungen ({ xref: personId | null }) importieren */
export const commitGedcom = (upload, treeId, decisions) =>
  request('POST', '/trees/import/commit', { upload, target: treeId, decisions })
