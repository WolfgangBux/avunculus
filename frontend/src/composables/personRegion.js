/**
 * Hilfen für markierte Personen auf Bildern (Ausschnitt fürs Vorschaubild).
 * Markiert wird in der Großansicht (MediaLightbox, Markierwerkzeug).
 */

/** Person direkt mit dem Bild verknüpft (nicht nur über eine ihrer Quellenangaben) */
export const isPersonLink = l => l.kind === 'person' && !l.citation

/** Überlappung zweier Rahmen (0–1, Schnitt durch Vereinigung) */
export function overlap(a, b) {
  const w = Math.min(a.left + a.width, b.left + b.width) - Math.max(a.left, b.left)
  const h = Math.min(a.top + a.height, b.top + b.height) - Math.max(a.top, b.top)
  if (w <= 0 || h <= 0) return 0
  return (w * h) / (a.width * a.height + b.width * b.height - w * h)
}

/**
 * In der Bilddatei markierte Personen (XMP aus Lightroom, digiKam …), die noch nicht erledigt sind –
 * erledigt, wenn eine markierte Person fast denselben Rahmen hat
 */
export function fileSuggestions(m) {
  return (m?.file_regions ?? [])
    .filter(s => !(m.links ?? []).some(l => isPersonLink(l) && l.crop && overlap(l.crop, s.crop) > 0.5))
}
