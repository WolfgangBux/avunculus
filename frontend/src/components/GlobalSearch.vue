<script setup>
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue'
import Dialog from 'openvue/dialog'
import { searchPersons } from '../api/persons.js'
import { searchPlaces } from '../api/places.js'
import { searchSources, sourceLabel } from '../api/sources.js'
import { searchMedia, isImage, mediaThumbUrl, mediaIcon } from '../api/media.js'
import { currentTreeId } from '../trees.js'
import { nameLabel, lifeSpan } from '../format.js'
import { t } from '../i18n/index.js'

/**
 * Suche über alles (Kopfzeile, Strg+K / ⌘K): Personen, Orte, Quellen und Medien gleichzeitig.
 * Pfeiltasten wählen, Enter öffnet den Treffer in seinem Tab.
 * emit('open', { type: 'person' | 'place' | 'source' | 'media', id })
 */
const emit = defineEmits(['open'])

const visible = ref(false)
const query   = ref('')
const groups  = ref([])        // [{ type, total, items: [{ id, title, detail, icon, thumb }] }]
const loading = ref(false)
const active  = ref(0)
const inputEl = ref(null)

const isMac  = /Mac|iPhone|iPad/.test(navigator.platform)
const hotkey = isMac ? '⌘K' : t('search.ctrlK')

/** „Wiesent“ statt „Wiesent, Wiesent, Regensburg, Oberpfalz …“ */
const shortPlace = pl => pl?.split(',')[0].trim() ?? ''

const LIMIT = { person: 8, place: 5, source: 5, media: 4 }
const GROUP_ORDER = ['person', 'place', 'source', 'media']

/** alle Treffer in Anzeigereihenfolge (für die Pfeiltasten) */
const flat = computed(() => groups.value.flatMap(g => g.items.map(i => ({ ...i, type: g.type }))))
const indexOf = (type, id) => flat.value.findIndex(i => i.type === type && i.id === id)

function open() {
  if (!currentTreeId.value) return
  visible.value = true
  nextTick(() => inputEl.value?.focus())
}
defineExpose({ open })

function onKey(e) {
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
    e.preventDefault()
    visible.value ? (visible.value = false) : open()
  }
}
onMounted(() => window.addEventListener('keydown', onKey))
onBeforeUnmount(() => window.removeEventListener('keydown', onKey))

// Beim Öffnen den letzten Suchbegriff markieren, damit man gleich neu tippen kann
watch(visible, v => { if (v) nextTick(() => inputEl.value?.select()) })
watch(currentTreeId, () => { query.value = ''; groups.value = [] })

let timer = null
let seq = 0
watch(query, q => {
  clearTimeout(timer)
  const term = q.trim()
  if (term.length < 2) { groups.value = []; loading.value = false; seq++; return }
  loading.value = true
  timer = setTimeout(() => run(term), 250)
})

async function run(term) {
  const my = ++seq
  const settle = p => p.then(r => r, () => null)   // ein Fehler in einem Bereich soll die anderen nicht verdecken
  const [persons, places, sources, media] = await Promise.all([
    settle(searchPersons(term, { limit: LIMIT.person })),
    settle(searchPlaces(term, LIMIT.place)),
    settle(searchSources(term, { limit: LIMIT.source })),
    settle(searchMedia(term, { limit: LIMIT.media })),
  ])
  if (my !== seq) return
  const out = {
    person: persons && { total: persons.total, items: persons.items.map(p => ({
      id: p.id, title: nameLabel(p), detail: p.private ? '' : [lifeSpan(p), shortPlace(p.birth_place || p.death_place)].filter(Boolean).join(' · '),
      icon: 'pi pi-user' })) },
    place: places && { total: null, items: places.map(p => ({
      id: p.id, title: p.name, detail: p.full_name !== p.name ? p.full_name : '', icon: 'pi pi-map-marker' })) },
    source: sources && { total: sources.total, items: sources.items.map(s => ({
      id: s.id, title: sourceLabel(s), detail: [s.author, s.repository_name].filter(Boolean).join(' · '), icon: 'pi pi-book' })) },
    media: media && { total: media.total, items: media.items.map(m => ({
      id: m.id, title: m.title || m.original_name, detail: m.title ? m.original_name : '', icon: 'pi ' + mediaIcon(m),
      thumb: isImage(m) ? mediaThumbUrl(m.id) : null })) },
  }
  groups.value = GROUP_ORDER.filter(k => out[k]?.items.length).map(k => ({ type: k, ...out[k] }))
  active.value = 0
  loading.value = false
}

function move(step) {
  if (!flat.value.length) return
  active.value = (active.value + step + flat.value.length) % flat.value.length
  nextTick(() => document.querySelector('.global-search-item.active')?.scrollIntoView({ block: 'nearest' }))
}
function choose(item = flat.value[active.value]) {
  if (!item) return
  visible.value = false
  emit('open', { type: item.type, id: item.id })
}
</script>

<template>
  <button class="search-btn" type="button" :disabled="!currentTreeId" :aria-label="t('search.label')"
    :title="t('search.label') + ' (' + hotkey + ')'" @click="open">
    <i class="pi pi-search" />
    <span class="search-btn-label">{{ t('search.label') }}</span>
    <kbd class="search-btn-key">{{ hotkey }}</kbd>
  </button>

  <Dialog v-model:visible="visible" modal dismissable-mask position="top" :show-header="false" class="global-search"
    :style="{ width: '40rem' }" :breakpoints="{ '700px': '95vw' }">
    <div class="global-search-input">
      <i :class="['pi', loading ? 'pi-spin pi-spinner' : 'pi-search']" />
      <input ref="inputEl" v-model="query" type="search" :placeholder="t('search.placeholder')" :aria-label="t('search.label')"
        autocomplete="off" @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)" @keydown.enter.prevent="choose()"
        @keydown.esc="visible = false" />
      <kbd>Esc</kbd>
    </div>

    <div class="global-search-results">
      <p v-if="query.trim().length < 2" class="muted global-search-hint">{{ t('search.hint') }}</p>
      <p v-else-if="!loading && !groups.length" class="muted global-search-hint">{{ t('search.none') }}</p>
      <section v-for="g in groups" :key="g.type">
        <h3>{{ t('search.groups.' + g.type) }}
          <small v-if="g.total != null && g.total > g.items.length">{{ t('search.more', { n: g.items.length, total: g.total.toLocaleString() }) }}</small>
        </h3>
        <ul>
          <li v-for="i in g.items" :key="i.id" :class="['global-search-item', { active: indexOf(g.type, i.id) === active }]"
            @mousemove="active = indexOf(g.type, i.id)" @click="choose({ ...i, type: g.type })">
            <img v-if="i.thumb" :src="i.thumb" alt="" class="global-search-thumb" loading="lazy" />
            <i v-else :class="[i.icon, 'global-search-icon']" />
            <div class="global-search-text">
              <div class="global-search-title">{{ i.title }}</div>
              <div v-if="i.detail" class="global-search-detail">{{ i.detail }}</div>
            </div>
            <i class="pi pi-arrow-right global-search-go" />
          </li>
        </ul>
      </section>
    </div>
  </Dialog>
</template>
