import { request, apiUrl } from './http.js'
import { currentTreeId } from '../trees.js'

/** Mediathek: { total, items } */
export function searchMedia(q = '', { limit = 60, offset = 0, type = '', unlinked = false } = {}) {
  const params = new URLSearchParams({ q, limit: String(limit), offset: String(offset) })
  if (type)     params.set('type', type)
  if (unlinked) params.set('unlinked', '1')
  return request('GET', `/media/search?${params}`)
}
export const fetchMedia  = (id)       => request('GET',    `/media/${id}`)
export const updateMedia = (id, data) => request('PUT',    `/media/${id}`, data)
export const deleteMedia = (id)       => request('DELETE', `/media/${id}`)

// <img src> / Links können keinen Header setzen – Baum als Parameter
export const mediaFileUrl  = (id, { download = false } = {}) =>
  apiUrl(`/media/${id}/file?tree=${currentTreeId.value}`) + (download ? '&download=1' : '')
export const mediaThumbUrl = (id) => apiUrl(`/media/${id}/thumb?tree=${currentTreeId.value}`)

export const isImage = m => (m?.mime_type ?? '').startsWith('image/')

/** Symbol für Dateien ohne Vorschaubild */
export function mediaIcon(m) {
  const mime = m?.mime_type ?? ''
  if (mime.startsWith('image/'))  return 'pi-image'
  if (mime === 'application/pdf') return 'pi-file-pdf'
  if (mime.startsWith('video/'))  return 'pi-video'
  if (mime.startsWith('audio/'))  return 'pi-volume-up'
  if (mime.includes('word') || mime.includes('opendocument.text')) return 'pi-file-word'
  if (mime.includes('excel') || mime.includes('spreadsheet'))      return 'pi-file-excel'
  return 'pi-file'
}

const CHUNK = 2 * 1024 * 1024   // bleibt sicher unter den PHP-Limits

function randomToken() {
  const bytes = new Uint8Array(16)
  crypto.getRandomValues(bytes)
  return [...bytes].map(b => b.toString(16).padStart(2, '0')).join('')
}

/**
 * Datei stückweise hochladen und als Medium anlegen.
 * Liefert das Medium; duplicate = true, wenn die Datei schon in der Mediathek war.
 * signal (AbortSignal): Abbrechen; wirft dann einen Fehler mit name = 'AbortError'.
 */
export async function uploadMedia(file, onProgress = () => {}, signal = undefined) {
  const token  = randomToken()
  const chunks = Math.max(1, Math.ceil(file.size / CHUNK))
  for (let i = 0; i < chunks; i++) {
    const last   = i === chunks - 1
    const params = new URLSearchParams({ upload: token, chunk: String(i) })
    if (last) { params.set('final', '1'); params.set('name', file.name) }
    onProgress(i / chunks)
    const res = await fetch(apiUrl(`/media/upload?${params}`), {
      method:  'POST',
      signal,
      headers: { 'Content-Type': 'application/octet-stream', 'X-Tree-Id': String(currentTreeId.value) },
      body:    file.slice(i * CHUNK, (i + 1) * CHUNK),
    })
    const json = await res.json().catch(() => ({ error: `HTTP ${res.status}` }))
    if (!res.ok) throw json
    if (last) { onProgress(1); return json }
  }
}
