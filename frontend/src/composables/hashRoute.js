import { watch, nextTick, onBeforeUnmount } from 'vue'
import { currentTreeId, setCurrentTree } from '../trees.js'

/**
 * Position in der Adresse: #/<Baum>/<Ansicht>[/<ID>]
 * z. B. #/44/persons/76094 – beim Neuladen landet man wieder dort; taugt auch als Lesezeichen.
 *
 * tabs: je Ansicht, welche ID sie in der Adresse merkt und wie sie sie wiederherstellt –
 *   { persons: { id: () => …, restore: id => … }, … }
 *   restore(null) heißt: in diesem Tab ist nichts ausgewählt (z. B. per „Zurück“ dorthin).
 * view:  Ref der aktuellen Ansicht
 * trees: Ref der verfügbaren Stammbäume (Baum aus der Adresse nur übernehmen, wenn es ihn gibt)
 */
export function useHashRoute({ view, tabs, trees }) {
  function currentHash() {
    if (!currentTreeId.value || !tabs[view.value]) return ''
    const id = tabs[view.value].id()
    return `#/${currentTreeId.value}/${view.value}` + (id ? `/${id}` : '')
  }

  function parseHash(hash = location.hash) {
    const m = hash.match(/^#\/(\d+)\/([a-z]+)(?:\/(\d+))?$/)
    return m && tabs[m[2]] ? { tree: +m[1], view: m[2], id: m[3] ? +m[3] : null } : null
  }

  let restoring = false
  async function applyHash(h) {
    if (!h) return
    restoring = true
    try {
      if (h.tree !== currentTreeId.value && trees.value.some(tr => tr.id === h.tree)) setCurrentTree(h.tree)
      await nextTick()   // erst Baumwechsel verarbeiten (setzt u. a. die gemerkte Hauptperson)
      if (h.tree !== currentTreeId.value) return   // Baum gibt es nicht (mehr)
      view.value = h.view
      await tabs[h.view].restore(h.id)
    } finally {
      restoring = false
      // während der Wiederherstellung unterdrückte Aktualisierung nachholen
      const cur = currentHash()
      if (cur && location.hash !== cur) history.replaceState(null, '', cur)
    }
  }

  // Jede Navigation wird ein Eintrag im Browser-Verlauf, damit Zurück/Vor funktionieren.
  // Ein Klick ändert die Adresse oft in mehreren Schritten (Tab wechseln, dann Datensatz laden) –
  // Änderungen kurz nach einem Eintrag ersetzen ihn daher, statt einen weiteren anzulegen.
  const MERGE_MS = 600
  let lastPush = 0
  watch(currentHash, h => {
    if (restoring || !h || location.hash === h) return
    const now = Date.now()
    if (parseHash() && now - lastPush > MERGE_MS) {
      history.pushState(null, '', h)
      lastPush = now
    } else {
      history.replaceState(null, '', h)   // erste Adresse bzw. Zwischenschritt
    }
  })

  // Zurück/Vor oder Adresse von Hand geändert / Lesezeichen innerhalb der App geöffnet.
  // Zurück/Vor löst je nach Browser popstate und hashchange aus – nur einmal anwenden.
  let applying = null
  function onLocationChange() {
    const h = parseHash()
    if (!h || location.hash === currentHash() || location.hash === applying) return
    applying = location.hash
    applyHash(h).finally(() => { applying = null })
  }
  window.addEventListener('popstate', onLocationChange)
  window.addEventListener('hashchange', onLocationChange)
  onBeforeUnmount(() => {
    window.removeEventListener('popstate', onLocationChange)
    window.removeEventListener('hashchange', onLocationChange)
  })

  return { parseHash, applyHash }
}
