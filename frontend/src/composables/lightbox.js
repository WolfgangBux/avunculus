import { ref } from 'vue'

/**
 * Großansicht eines Mediums für die ganze App (MediaLightbox, in App.vue eingebunden).
 * null = geschlossen; sonst { media: { id, title, mime_type }, crop?, marks?, editNow? }
 *   crop:  Bereich, auf den anfangs gezoomt und der umrahmt wird (die zitierte Stelle einer Quellenangabe)
 *   marks: was sich mit dem Markierwerkzeug (oben rechts) markieren lässt – nur Bilder, nur für Bearbeiter:
 *     persons:  Personen-ID (nur diese Person) oder 'all' (alle direkt mit dem Bild verknüpften Personen);
 *               gespeichert wird sofort (PUT media/{id}/region), danach onSaved(medium mit links)
 *     citation: { save(crop) } – Bereich der Transkription; gespeichert wird mit der Quellenangabe
 *   editNow: gleich mit dem Markieren beginnen – 'citation' oder 'p<Personen-ID>'
 */
export const lightbox = ref(null)

/** Typen, die die Großansicht selbst zeigen kann; andere Dateien öffnen weiter in einem neuen Tab */
export const canShowInLightbox = m => /^(image\/(jpeg|png|gif|webp|bmp|svg\+xml)|application\/pdf|video\/|audio\/)/.test(m?.mime_type ?? '')

export function openLightbox(media, { crop = null, marks = null, editNow = null } = {}) {
  lightbox.value = { media, crop, marks, editNow }
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
