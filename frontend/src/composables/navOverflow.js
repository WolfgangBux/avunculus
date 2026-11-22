import { ref, nextTick, onMounted, onBeforeUnmount } from 'vue'

/**
 * Wie viele Einträge der Hauptnavigation passen in die Leiste? Der Rest wandert ins „Mehr“-Menü.
 *   nav:   Ref der Leiste (ihre Breite ist der verfügbare Platz – sie hängt nicht vom Inhalt ab)
 *   items: Ref eines Arrays der Einträge in Reihenfolge (Elemente)
 *   more:  Ref des „Mehr“-Knopfs
 * Gibt { visible, measuring, update } zurück: visible = Anzahl der Einträge, die in der Leiste stehen.
 *
 * Zum Messen werden kurz alle Einträge (und der Mehr-Knopf) gezeigt; das geschieht vor dem
 * nächsten Bild, flackert also nicht. Gemessen wird bei jeder Größenänderung, weil sich
 * Schrift und Abstände an Bildschirmbreiten ändern (main.css).
 */
export function useNavOverflow({ nav, items, more }) {
  const visible   = ref(Infinity)
  const measuring = ref(false)
  let frame = 0

  async function update() {
    measuring.value = true
    visible.value = Infinity
    await nextTick()
    const el = nav.value
    const list = items.value.filter(Boolean)
    if (!el || !list.length) { measuring.value = false; return }
    const left  = el.getBoundingClientRect().left
    const avail = el.clientWidth
    const gap   = parseFloat(getComputedStyle(el).columnGap) || 0
    const right = list.map(i => i.getBoundingClientRect().right - left)
    let n = list.length
    if (right[n - 1] > avail) {
      const moreW = more.value?.getBoundingClientRect().width ?? 0
      n = 0
      while (n < list.length && right[n] + gap + moreW <= avail) n++
    }
    visible.value = n
    measuring.value = false
  }

  const schedule = () => { cancelAnimationFrame(frame); frame = requestAnimationFrame(update) }
  let observer
  onMounted(() => {
    observer = new ResizeObserver(schedule)
    if (nav.value) observer.observe(nav.value)
    schedule()
  })
  onBeforeUnmount(() => { observer?.disconnect(); cancelAnimationFrame(frame) })

  return { visible, measuring, update: schedule }
}
