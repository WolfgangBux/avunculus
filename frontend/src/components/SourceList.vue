<script setup>
import { ref, computed, watch, onMounted, nextTick } from 'vue'
import Button from 'openvue/button'
import IconField from 'openvue/iconfield'
import InputIcon from 'openvue/inputicon'
import InputText from 'openvue/inputtext'
import { searchSources, deleteSource } from '../api/sources.js'
import { useAsk } from '../composables/feedback.js'
import { usePagedSearch } from '../composables/pagedSearch.js'
import { t } from '../i18n/index.js'
import { canEdit } from '../auth.js'

const PAGE = 100

const props = defineProps({
  refresh:        Number,
  selectedId:     { type: [Number, String], default: null },
  selectedSource: { type: Object, default: null },   // vollständig geladene Quelle (falls nicht in der Liste)
})
const emit = defineEmits(['edit', 'create'])

const ask = useAsk()

// Suche und Seiten kommen vom Server, wie bei Personen und Familien
const { query, items: sources, total, loading, error, reload, loadMore } =
  usePagedSearch((q, opts) => searchSources(q, opts), { pageSize: PAGE })

async function remove(s) {
  if (!(await ask(t('source.list.deleteConfirm', { title: s.title })))) return
  await deleteSource(s.id)
  await reload()
}

onMounted(reload)
watch(() => props.refresh, reload)

const isSelected = s => props.selectedId != null && String(s.id) === String(props.selectedId)

// Gewählte Quelle, die (noch) nicht in den geladenen Seiten steht, oben anheften
const rows = computed(() => {
  const sel = props.selectedSource
  if (!sel || sources.value.some(isSelected)) return sources.value
  return [{ ...sel, _pinned: true }, ...sources.value]
})

const meta = s => [s.author, s.abbreviation && s.abbreviation !== s.title ? s.abbreviation : null,
  [s.repository_name, s.call_number].filter(Boolean).join(', ')]
  .filter(Boolean).join(' · ')

// gewählte Quelle sichtbar machen
const listEl = ref(null)
watch(() => [props.selectedId, sources.value.length], async () => {
  await nextTick()
  listEl.value?.querySelector('.source-card.selected')?.scrollIntoView({ block: 'nearest' })
})
</script>

<template>
  <div class="card">
    <div class="list-header">
      <h2 class="card-title">{{ t('source.list.title') }}</h2>
      <span v-if="total || query" class="list-count">{{ t('source.list.total', { n: total.toLocaleString() }) }}</span>
    </div>

    <div class="list-toolbar">
      <IconField v-if="total || query" class="list-search">
        <InputIcon :class="['pi', loading ? 'pi-spin pi-spinner' : 'pi-search']" />
        <InputText v-model="query" type="search" :placeholder="t('source.list.search')" fluid />
      </IconField>
      <Button v-if="canEdit" :label="t('source.list.new')" icon="pi pi-plus" size="small" @click="emit('create')" />
    </div>

    <p v-if="loading && !sources.length" class="muted">{{ t('common.loading') }}</p>
    <div v-else-if="error" class="alert alert-error">{{ t('source.list.loadError') }}</div>
    <p v-else-if="!sources.length && !query" class="muted">{{ t('source.list.empty') }}</p>
    <p v-else-if="!sources.length" class="muted">{{ t('person.list.noMatch') }}</p>

    <ul v-else ref="listEl" class="person-list">
      <li v-for="s in rows" :key="(s._pinned ? 'pin-' : '') + s.id"
        :class="['person-card', 'source-card', { selected: isSelected(s), pinned: s._pinned }]"
        tabindex="0" @click="emit('edit', s)" @keydown.enter="emit('edit', s)">
        <div class="source-icon" aria-hidden="true"><i class="pi pi-book" /></div>
        <div class="person-info">
          <div class="person-name source-title" :title="s.title">{{ s.title }}</div>
          <div v-if="meta(s)" class="name-detail">{{ meta(s) }}</div>
        </div>
        <div class="row-actions" @click.stop>
          <button v-if="canEdit" class="icon-btn danger" :title="t('action.delete')" :aria-label="t('action.delete')" @click="remove(s)"><i class="pi pi-trash" /></button>
        </div>
      </li>
      <li v-if="sources.length < total" class="list-more">
        <Button :label="t('list.loadMore', { n: Math.min(PAGE, total - sources.length), rest: (total - sources.length).toLocaleString() })"
          icon="pi pi-angle-down" text size="small" :loading="loading" @click="loadMore" />
      </li>
    </ul>
  </div>
</template>
