/**
 * Rahmen auf einem Bild aufziehen, verschieben oder an einer Ecke ändern (Markierungen auf Medien).
 * Koordinaten sind Anteile (0–1) des angezeigten, also richtig gedrehten Bilds.
 *
 *   const { down, move, up } = useRegionDrag(img, draft, () => bearbeitbar)
 *   <div @pointerdown="down" @pointermove="move" @pointerup="up" @pointercancel="up">
 *     <img ref="img"> … <div data-frame="1"><span data-handle="nw"> …
 *
 * draft: ref auf { left, top, width, height, shape? } oder null; ein neuer Rahmen übernimmt die Form des alten.
 */
const MIN_PX = 8   // kleiner (auf dem Bildschirm) = versehentlicher Klick
const clamp = (v, lo = 0, hi = 1) => Math.min(hi, Math.max(lo, v))

/** CSS-Position eines Rahmens */
export const regionBox = c => ({ left: c.left * 100 + '%', top: c.top * 100 + '%', width: c.width * 100 + '%', height: c.height * 100 + '%' })

export function useRegionDrag(img, draft, active = () => true) {
  let drag = null

  function point(e) {
    const r = img.value.getBoundingClientRect()
    return { x: clamp((e.clientX - r.left) / r.width), y: clamp((e.clientY - r.top) / r.height) }
  }

  function down(e) {
    if (!active() || e.button > 0) return
    const p = point(e)
    const handle = e.target.dataset?.handle
    const shape  = draft.value?.shape ?? 'rect'
    if (handle && draft.value)                         drag = { mode: 'resize', handle, orig: { ...draft.value } }
    else if (e.target.dataset?.frame && draft.value)   drag = { mode: 'move', start: p, orig: { ...draft.value } }
    else { drag = { mode: 'draw', start: p, orig: draft.value }; draft.value = { left: p.x, top: p.y, width: 0, height: 0, shape } }
    e.currentTarget.setPointerCapture(e.pointerId)
    e.preventDefault()
  }

  function move(e) {
    if (!drag) return
    const p = point(e), o = drag.orig
    if (drag.mode === 'draw') {
      const s = drag.start
      draft.value = { ...draft.value, left: Math.min(s.x, p.x), top: Math.min(s.y, p.y), width: Math.abs(p.x - s.x), height: Math.abs(p.y - s.y) }
    } else if (drag.mode === 'move') {
      draft.value = { ...o, left: clamp(o.left + p.x - drag.start.x, 0, 1 - o.width), top: clamp(o.top + p.y - drag.start.y, 0, 1 - o.height) }
    } else {
      // gegenüberliegende Ecke bleibt stehen
      const fx = drag.handle.includes('w') ? o.left + o.width : o.left
      const fy = drag.handle.includes('n') ? o.top + o.height : o.top
      draft.value = { ...o, left: Math.min(fx, p.x), top: Math.min(fy, p.y), width: Math.abs(p.x - fx), height: Math.abs(p.y - fy) }
    }
  }

  function up() {
    if (!drag) return
    // Versehentlicher Klick ohne Ziehen: alten Rahmen behalten (gemessen am angezeigten, ggf. gezoomten Bild)
    const r = img.value?.getBoundingClientRect()
    if (draft.value && r && (draft.value.width * r.width < MIN_PX || draft.value.height * r.height < MIN_PX)) draft.value = drag.orig
    drag = null
  }

  return { down, move, up }
}
