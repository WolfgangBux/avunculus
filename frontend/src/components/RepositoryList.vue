<script setup>
import SourceSwitch from './SourceSwitch.vue'
import { ref, computed, watch, onMounted, nextTick } from 'vue'
import Button from 'openvue/button'
import IconField from 'openvue/iconfield'
import InputIcon from 'openvue/inputicon'
import InputText from 'openvue/inputtext'
import { fetchRepositories, deleteRepository } from '../api/repositories.js'
import { useAsk } from '../composables/feedback.js'
import { t } from '../i18n/index.js'
import { canEdit } from '../auth.js'

const props = defineProps({
  refresh:    Number,
  selectedId: { type: [Number, String], default: null },
})
const emit = defineEmits(['edit', 'create', 'deleted', 'switch'])

const ask = useAsk()

const repos   = ref([])
const loading = ref(false)
const error   = ref('')
const query   = ref('')

async function load() {
  loading.value = true
  error.value   = ''
  try   { repos.value = await fetchRepositories() }
  catch { error.value = t('repository.loadError') }
  finally { loading.value = false }
}

async function remove(r) {
  if (!(await ask(t('repository.deleteConfirm', { name: r.name, n: r.source_count })))) return
  await deleteRepository(r.id)
  await load()
  emit('deleted', r.id)
}

onMounted(load)
watch(() => props.refresh, load)

const filtered = computed(() => {
  const q = query.value.trim().toLowerCase()
  if (!q) return repos.value
  return repos.value.filter(r => [r.name, r.city, r.country].filter(Boolean).join(' ').toLowerCase().includes(q))
})

const isSelected = r => props.selectedId != null && String(r.id) === String(props.selectedId)
const meta = r => [
  [r.postal_code, r.city].filter(Boolean).join(' '),
  r.country,
  t(r.source_count === 1 ? 'repository.oneSource' : 'repository.sources', { n: r.source_count.toLocaleString() }),
].filter(Boolean).join(' · ')

const listEl = ref(null)
watch(() => [props.selectedId, filtered.value.length], async () => {
  await nextTick()
  listEl.value?.querySelector('.source-card.selected')?.scrollIntoView({ block: 'nearest' })
})
</script>

<template>
  <div class="card">
    <div class="list-header">
      <h2 class="sr-only">{{ t('repository.title') }}</h2>
      <SourceSwitch active="repositories" @switch="v => emit('switch', v)" />
      <span v-if="repos.length" class="list-count">
        {{ query ? t('person.list.count', { n: filtered.length, total: repos.length }) : t('repository.total', { n: repos.length }) }}
      </span>
    </div>

    <div class="list-toolbar">
      <IconField v-if="repos.length" class="list-search">
        <InputIcon class="pi pi-search" />
        <InputText v-model="query" type="search" :placeholder="t('repository.search')" fluid />
      </IconField>
      <Button v-if="canEdit" :label="t('repository.new')" icon="pi pi-plus" size="small" @click="emit('create')" />
    </div>

    <p v-if="loading && !repos.length" class="muted">{{ t('common.loading') }}</p>
    <div v-else-if="error" class="alert alert-error">{{ error }}</div>
    <p v-else-if="!repos.length" class="muted">{{ t('repository.empty') }}</p>
    <p v-else-if="!filtered.length" class="muted">{{ t('person.list.noMatch') }}</p>

    <ul v-else ref="listEl" class="person-list">
      <li v-for="r in filtered" :key="r.id"
        :class="['person-card', 'source-card', { selected: isSelected(r) }]"
        tabindex="0" @click="emit('edit', r)" @keydown.enter="emit('edit', r)">
        <div class="source-icon" aria-hidden="true"><i class="pi pi-building" /></div>
        <div class="person-info">
          <div class="person-name source-title" :title="r.name">{{ r.name }}</div>
          <div class="name-detail">{{ meta(r) }}</div>
        </div>
        <div class="row-actions" @click.stop>
          <button v-if="canEdit" class="icon-btn danger" :title="t('action.delete')" :aria-label="t('action.delete')" @click="remove(r)"><i class="pi pi-trash" /></button>
        </div>
      </li>
    </ul>
  </div>
</template>
