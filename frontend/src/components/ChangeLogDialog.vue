<script setup>
import { ref, computed, watch } from 'vue'
import Dialog from 'openvue/dialog'
import Button from 'openvue/button'
import Select from 'openvue/select'
import IconField from 'openvue/iconfield'
import InputIcon from 'openvue/inputicon'
import InputText from 'openvue/inputtext'
import { fetchChanges, changeTime, changeAction, changeStats, changeFields, CHANGE_TYPES, CHANGE_VIEWS } from '../api/changes.js'
import { changeLogFilter } from '../composables/changeLog.js'
import { usePagedSearch } from '../composables/pagedSearch.js'
import { currentTreeId } from '../trees.js'
import { t } from '../i18n/index.js'

/** Änderungsprotokoll des aktuellen Baums: wer hat wann was geändert. Klick öffnet den Datensatz. */
const PAGE = 50
const ICONS = { person: 'pi-user', family: 'pi-users', source: 'pi-book', repository: 'pi-building',
                place: 'pi-map-marker', media: 'pi-image', tree: 'pi-sitemap' }

const visible = computed({
  get: () => changeLogFilter.value !== null,
  set: v => { if (!v) changeLogFilter.value = null },
})

const type   = ref('all')   // 'all' statt '' – die Auswahlliste zeigt '' als „nichts gewählt“
const user   = ref(0)      // 0 = alle (null kann die Auswahlliste nicht als Wert zeigen)
const entity = ref(null)    // { id, label } – nur dieser Datensatz (aus „Verlauf“ eines Formulars)
const users  = ref([])

const { query, items, total, loading, error, reload, loadMore } = usePagedSearch(async (q, opts) => {
  const res = await fetchChanges({ q, type: type.value === 'all' ? '' : type.value, id: entity.value?.id, user: user.value, ...opts })
  if (res.users) users.value = res.users
  return res
}, { pageSize: PAGE })

watch(changeLogFilter, f => {
  if (!f) return
  type.value   = f.type ?? 'all'
  entity.value = f.id ? { id: f.id, label: f.label ?? '' } : null
  user.value   = 0
  query.value  = ''
  reload()
}, { immediate: true })
watch([type, user], () => { if (visible.value) reload() })

// Geänderte Felder: die ersten FIELDS_SHOWN, Rest aufklappbar
const FIELDS_SHOWN = 4
const expanded = ref(new Set())
const fieldsOf = c => { const f = changeFields(c); return expanded.value.has(c.id) ? f : f.slice(0, FIELDS_SHOWN) }
const moreFields = c => Math.max(0, changeFields(c).length - FIELDS_SHOWN)
function toggleFields(c) {
  const s = new Set(expanded.value)
  s.has(c.id) ? s.delete(c.id) : s.add(c.id)
  expanded.value = s
}

function clearEntity() { entity.value = null; type.value = 'all'; reload() }

const typeOptions = computed(() => [{ value: 'all', label: t('changes.allTypes') },
  ...CHANGE_TYPES.map(v => ({ value: v, label: t('changes.types.' + v) }))])
const userOptions = computed(() => [{ value: 0, label: t('changes.allUsers') },
  ...users.value.map(u => ({ value: u.id, label: u.name }))])

const canOpen = c => c.exists && CHANGE_VIEWS[c.entity_type]
function open(c) {
  if (!canOpen(c)) return
  location.hash = `#/${currentTreeId.value}/${CHANGE_VIEWS[c.entity_type]}/${c.entity_id}`
  visible.value = false
}
</script>

<template>
  <Dialog v-model:visible="visible" modal :draggable="false" :header="t('changes.title')"
    :style="{ width: '46rem' }" :breakpoints="{ '760px': '95vw' }">

    <div class="changes-toolbar">
      <IconField class="changes-search">
        <InputIcon :class="['pi', loading ? 'pi-spin pi-spinner' : 'pi-search']" />
        <InputText v-model="query" type="search" :placeholder="t('changes.search')" fluid />
      </IconField>
      <Select v-model="type" :options="typeOptions" option-label="label" option-value="value" :disabled="!!entity" class="changes-filter" />
      <Select v-model="user" :options="userOptions" option-label="label" option-value="value" class="changes-filter" />
    </div>
    <div v-if="entity" class="changes-entity">
      <i :class="['pi', ICONS[type]]" />
      {{ t('changes.onlyEntity', { label: entity.label || '#' + entity.id }) }}
      <Button icon="pi pi-times" text rounded size="small" :aria-label="t('changes.showAll')" v-tooltip.top="t('changes.showAll')" @click="clearEntity" />
    </div>

    <p v-if="loading && !items.length" class="muted">{{ t('common.loading') }}</p>
    <div v-else-if="error" class="alert alert-error">{{ t('changes.loadError') }}</div>
    <p v-else-if="!items.length" class="muted">{{ query ? t('person.list.noMatch') : t('changes.empty') }}</p>

    <ul v-else class="changes-list">
      <li v-for="c in items" :key="c.id" :class="['change-item', { openable: canOpen(c) }]"
        :tabindex="canOpen(c) ? 0 : -1" @click="open(c)" @keydown.enter="open(c)">
        <i :class="['pi', ICONS[c.entity_type] ?? 'pi-circle', 'change-icon']" aria-hidden="true" />
        <div class="change-main">
          <div class="change-label">
            <span :class="{ 'change-gone': c.entity_id && !c.exists }">{{ c.entity_label || (c.entity_id ? '#' + c.entity_id : t('changes.types.' + c.entity_type)) }}</span>
          </div>
          <div class="change-action">{{ changeAction(c) }}</div>
          <div v-if="changeStats(c)" class="change-stats muted">{{ changeStats(c) }}</div>
          <ul v-if="c.details?.changes" class="change-fields">
            <li v-for="(f, i) in fieldsOf(c)" :key="i" :class="'cf-' + f.kind">
              <span class="cf-label">{{ f.label }}</span>
              <template v-if="f.kind === 'changed'"><del>{{ f.old }}</del> → <ins>{{ f.new }}</ins></template>
              <template v-else-if="f.kind === 'added'"><span class="cf-sign">+</span> <ins>{{ f.new }}</ins></template>
              <template v-else-if="f.kind === 'removed'"><span class="cf-sign">−</span> <del>{{ f.old }}</del></template>
            </li>
            <li v-if="moreFields(c)">
              <button type="button" class="cf-toggle" @click.stop="toggleFields(c)">
                {{ expanded.has(c.id) ? t('changes.fewer') : t('changes.moreFields', { n: moreFields(c) }) }}
              </button>
            </li>
          </ul>
        </div>
        <div class="change-meta muted">
          <div>{{ changeTime(c.created_at) }}</div>
          <div>{{ c.user_name }}</div>
        </div>
      </li>
      <li v-if="items.length < total" class="list-more">
        <Button :label="t('list.loadMore', { n: Math.min(PAGE, total - items.length), rest: (total - items.length).toLocaleString() })"
          icon="pi pi-angle-down" text size="small" :loading="loading" @click="loadMore" />
      </li>
    </ul>

    <template #footer>
      <span class="muted changes-total">{{ t('changes.total', { n: total.toLocaleString() }) }}</span>
      <Button :label="t('action.close')" severity="secondary" outlined @click="visible = false" />
    </template>
  </Dialog>
</template>

<style scoped>
.changes-toolbar { display: flex; flex-wrap: wrap; gap: .5rem; margin-bottom: .6rem; }
.changes-search { flex: 1 1 14rem; }
.changes-filter { flex: 0 1 11rem; min-width: 0; }
.changes-entity { display: flex; align-items: center; gap: .4rem; font-size: .85rem; margin-bottom: .5rem;
  padding: .2rem .2rem .2rem .6rem; border-radius: var(--radius-sm); background: var(--color-primary-soft); }
.changes-entity .pi { color: var(--color-primary); font-size: .8rem; }
.changes-entity button { margin-left: auto; }
.changes-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: .3rem; max-height: 60vh; overflow-y: auto; }
.change-item { display: flex; align-items: flex-start; gap: .6rem; padding: .45rem .6rem;
  border: 1px solid var(--color-border); border-radius: var(--radius-sm); background: var(--color-surface); }
.change-item.openable { cursor: pointer; }
.change-item.openable:hover { border-color: var(--color-primary); background: var(--color-primary-soft); }
.change-icon { color: var(--color-primary); font-size: .85rem; margin-top: .2rem; }
.change-main { flex: 1; min-width: 0; }
.change-label { font-weight: 600; font-size: .88rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.change-gone { text-decoration: line-through; color: var(--color-muted); font-weight: 500; }
.change-action { font-size: .8rem; }
.change-stats { font-size: .75rem; }
.change-meta { font-size: .75rem; text-align: right; white-space: nowrap; }
.change-fields { list-style: none; padding: 0; margin: .25rem 0 0; display: flex; flex-direction: column; gap: .1rem; font-size: .78rem; }
.change-fields li { overflow-wrap: anywhere; }
.cf-label { color: var(--color-muted); margin-right: .3rem; }
.cf-label::after { content: ':'; }
.cf-more .cf-label::after { content: ''; }
.change-fields del { color: var(--color-muted); }
.change-fields ins { text-decoration: none; background: var(--color-primary-soft); border-radius: 3px; padding: 0 .2rem; }
.cf-removed del { color: inherit; }
.cf-sign { font-weight: 600; color: var(--color-muted); }
.cf-toggle { border: none; background: none; padding: 0; font: inherit; color: var(--color-primary); cursor: pointer; }
.changes-total { margin-right: auto; font-size: .8rem; align-self: center; }
</style>
