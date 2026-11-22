import { ref, watch } from 'vue'

/**
 * Serverseitige Suche mit seitenweisem Nachladen.
 * fetchPage(q, { limit, offset, around? }) muss { total, items } liefern – bei around zusätzlich offset,
 * die Stelle der ersten gelieferten Zeile (die Seite, auf der dieser Datensatz steht).
 * Die geladenen Zeilen sind ein Ausschnitt ab `start`; davor lädt loadBefore, dahinter loadMore nach.
 * Antworten auf veraltete Anfragen (schnelles Tippen) werden verworfen.
 */
export function usePagedSearch(fetchPage, { pageSize = 100, debounce = 250 } = {}) {
  const query   = ref('')
  const items   = ref([])
  const total   = ref(0)
  const start   = ref(0)   // Stelle der ersten geladenen Zeile in der ganzen Liste
  const loading = ref(false)
  const error   = ref(null)
  let seq   = 0
  let timer = null

  // mode: 'reset' (von vorn), 'more' (dahinter), 'before' (davor), 'around' (Seite mit Datensatz aroundId)
  async function load(mode = 'reset', aroundId = null) {
    const my = ++seq
    loading.value = true
    try {
      let opts
      if (mode === 'more')        opts = { limit: pageSize, offset: start.value + items.value.length }
      else if (mode === 'before') { const from = Math.max(0, start.value - pageSize); opts = { limit: start.value - from, offset: from } }
      else if (mode === 'around') opts = { limit: pageSize, offset: 0, around: aroundId }
      else                        opts = { limit: pageSize, offset: 0 }
      const res = await fetchPage(query.value.trim(), opts)
      if (my !== seq) return
      total.value = res.total
      if (mode === 'more')        items.value = [...items.value, ...res.items]
      else if (mode === 'before') { items.value = [...res.items, ...items.value]; start.value = opts.offset }
      else                        { items.value = res.items; start.value = res.offset ?? opts.offset }
      error.value = null
    } catch (e) {
      if (my === seq) error.value = e
    } finally {
      if (my === seq) loading.value = false
    }
  }

  watch(query, () => {
    clearTimeout(timer)
    timer = setTimeout(() => load('reset'), debounce)
  })

  return {
    query, items, total, start, loading, error,
    reload:     () => load('reset'),
    loadMore:   () => load('more'),
    loadBefore: () => load('before'),
    loadAround: id => load('around', id),
  }
}
