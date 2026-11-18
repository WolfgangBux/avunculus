<script setup>
import { watch, onMounted } from 'vue'
import Button from 'openvue/button'
import IconField from 'openvue/iconfield'
import InputIcon from 'openvue/inputicon'
import InputText from 'openvue/inputtext'
import { searchFamilies, deleteFamily } from '../api/families.js'
import { extractYear } from '../gedcom.js'
import { nameLabel, sexClass } from '../format.js'
import { useAsk, useNotify } from '../composables/feedback.js'
import { usePagedSearch } from '../composables/pagedSearch.js'
import { t } from '../i18n/index.js'
import { canEdit } from '../auth.js'

const PAGE = 100

const props = defineProps({
  refresh:    Number,
  selectedId: { type: Number, default: null },
})
const emit = defineEmits(['select', 'create', 'deleted'])

const ask    = useAsk()
const notify = useNotify()

function toItem(f, byId) {
  const partners = f.members.filter(m => m.role !== 'CHIL').map(m => byId.get(m.person_id)).filter(Boolean)
  const children = f.members.filter(m => m.role === 'CHIL')
  const marr     = (f.events ?? []).find(e => e.event_type === 'MARR')
  const div      = (f.events ?? []).find(e => ['DIV', 'ANUL'].includes(e.event_type))
  return {
    id: f.id,
    partners,
    childCount: children.length,
    married:  marr ? (extractYear(marr.event_date) ?? '') : null,
    divorced: div  ? (extractYear(div.event_date)  ?? '') : null,
  }
}

// Suche und Seiten kommen vom Server (Namen von Partnern und Kindern)
const { query, items, total, loading, error, reload, loadMore } = usePagedSearch(async (q, opts) => {
  const res  = await searchFamilies(q, opts)
  const byId = new Map(res.persons.map(p => [Number(p.id), p]))
  return { total: res.total, items: res.families.map(f => toItem(f, byId)) }
}, { pageSize: PAGE })

onMounted(reload)
watch(() => props.refresh, reload)

function title(item) {
  if (item.partners.length) return item.partners.map(nameLabel)
  return [t('families.noPartners')]
}

async function remove(item) {
  const name = item.partners.map(nameLabel).join(' & ') || t('families.noPartners')
  if (!(await ask(t('families.deleteConfirm', { name })))) return
  try {
    await deleteFamily(item.id)
    await reload()
    emit('deleted', item.id)
  } catch {
    notify.error(t('family.error.delete'))
  }
}
</script>

<template>
  <div class="card">
    <div class="list-header">
      <h2 class="card-title">{{ t('families.title') }}</h2>
      <span v-if="total || query" class="list-count">{{ total === 1 ? t('families.totalOne') : t('families.total', { n: total.toLocaleString() }) }}</span>
    </div>

    <div class="list-toolbar">
      <IconField class="list-search">
        <InputIcon :class="['pi', loading ? 'pi-spin pi-spinner' : 'pi-search']" />
        <InputText v-model="query" type="search" :placeholder="t('families.search')" fluid />
      </IconField>
      <Button v-if="canEdit" :label="t('families.new')" icon="pi pi-plus" size="small" @click="emit('create')" />
    </div>

    <p v-if="loading && !items.length" class="muted">{{ t('common.loading') }}</p>
    <div v-else-if="error" class="alert alert-error">{{ t('families.loadError') }}</div>
    <p v-else-if="!items.length && !query" class="muted">{{ t('families.empty') }}</p>
    <p v-else-if="!items.length" class="muted">{{ t('person.list.noMatch') }}</p>

    <ul v-else class="person-list">
      <li
        v-for="item in items" :key="item.id"
        :class="['person-card', 'family-row', { selected: item.id === selectedId }]"
        tabindex="0"
        @click="emit('select', item.id)"
        @keydown.enter="emit('select', item.id)"
      >
        <div class="couple-avatars" aria-hidden="true">
          <div v-for="(p, i) in item.partners.slice(0, 2)" :key="i" :class="['avatar', 'avatar-xs', sexClass(p)]">
            <svg viewBox="0 0 32 32" fill="currentColor"><circle cx="16" cy="12" r="6"/><path d="M4 32c0-7 5.4-11 12-11s12 4 12 11z"/></svg>
          </div>
          <div v-if="!item.partners.length" class="avatar avatar-xs sex-U"><i class="pi pi-users" /></div>
        </div>

        <div class="person-info">
          <div v-for="(line, i) in title(item)" :key="i" class="person-name family-name">{{ line }}</div>
          <div class="person-years">
            <span v-if="item.married !== null"><i class="pi pi-heart family-meta-icon" /> {{ item.married || '?' }}</span>
            <span v-if="item.divorced !== null"> · <i class="pi pi-ban family-meta-icon" /> {{ item.divorced || '?' }}</span>
            <span v-if="item.married !== null || item.divorced !== null"> · </span>
            {{ t(item.childCount === 1 ? 'families.oneChild' : 'families.children', { n: item.childCount }) }}
          </div>
        </div>

        <div class="row-actions" @click.stop>
          <button v-if="canEdit" class="icon-btn danger" :title="t('action.delete')" :aria-label="t('action.delete')" @click="remove(item)"><i class="pi pi-trash" /></button>
        </div>
      </li>
      <li v-if="items.length < total" class="list-more">
        <Button :label="t('list.loadMore', { n: Math.min(PAGE, total - items.length), rest: (total - items.length).toLocaleString() })"
          icon="pi pi-angle-down" text size="small" :loading="loading" @click="loadMore" />
      </li>
    </ul>
  </div>
</template>
