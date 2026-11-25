import { ref } from 'vue'

/**
 * Großansicht eines Mediums für die ganze App (MediaLightbox, in App.vue eingebunden).
 * null = geschlossen; sonst { media: { id, title, mime_type }, crop? } – crop: Bereich, auf den
 * anfangs gezoomt wird (z. B. die zitierte Stelle einer Quellenangabe).
 */
export const lightbox = ref(null)

/** Typen, die die Großansicht selbst zeigen kann; andere Dateien öffnen weiter in einem neuen Tab */
export const canShowInLightbox = m => /^(image\/(jpeg|png|gif|webp|bmp|svg\+xml)|application\/pdf|video\/|audio\/)/.test(m?.mime_type ?? '')

export function openLightbox(media, { crop = null } = {}) {
  lightbox.value = { media, crop }
}

/**
 * Klick auf einen Medien-Link: Großansicht statt neuem Tab. Strg/⌘/Umschalt-Klick und mittlere
 * Maustaste behalten das Verhalten des Browsers (neuer Tab), ebenso Dateien ohne Vorschau.
 */
export function onMediaLinkClick(e, media, opts) {
  if (e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0 || !canShowInLightbox(media)) return
  e.preventDefault()
  openLightbox(media, opts)
}
