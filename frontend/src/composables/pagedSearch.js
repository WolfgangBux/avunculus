import { ref, watch } from 'vue'

/**
 * Serverseitige Suche mit seitenweisem Nachladen.
 * fetchPage(q, { limit, offset }) muss { total, items } liefern.
 * Antworten auf veraltete Anfragen (schnelles Tippen) werden verworfen.
 */
export function usePagedSearch(fetchPage, { pageSize = 100, debounce = 250 } = {}) {
  const query   = ref('')
  const items   = ref([])
  const total   = ref(0)
  const loading = ref(false)
  const error   = ref(null)
  let seq   = 0
  let timer = null

  async function load(reset = true) {
    const my = ++seq
    loading.value = true
    try {
      const res = await fetchPage(query.value.trim(), { limit: pageSize, offset: reset ? 0 : items.value.length })
      if (my !== seq) return
      total.value = res.total
      items.value = reset ? res.items : [...items.value, ...res.items]
      error.value = null
    } catch (e) {
      if (my === seq) error.value = e
    } finally {
      if (my === seq) loading.value = false
    }
  }

  watch(query, () => {
    clearTimeout(timer)
    timer = setTimeout(() => load(true), debounce)
  })

  return { query, items, total, loading, error, reload: () => load(true), loadMore: () => load(false) }
}
