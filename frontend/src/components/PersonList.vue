<script>
import { ref, computed, watch, onMounted, nextTick, defineComponent } from 'vue'
import { searchPersons, deletePerson } from '../api/persons.js'
import { nameLabel as primaryNameLabel, lifeSpan, sexClass } from '../format.js'
import { t } from '../i18n/index.js'
import { useAsk } from '../composables/feedback.js'
import { usePagedSearch } from '../composables/pagedSearch.js'
import { mediaThumbUrl } from '../api/media.js'
import { canEdit } from '../auth.js'
import IconField from 'openvue/iconfield'
import InputIcon from 'openvue/inputicon'
import InputText from 'openvue/inputtext'
import Button from 'openvue/button'

const PAGE = 100

export default defineComponent({
  components: { IconField, InputIcon, InputText, Button },
  props: {
    refresh:    Number,
    selectedId:     { type: [Number, String], default: null },
    selectedPerson: { type: Object, default: null },   // vollständig geladene Person (falls nicht in der Liste)
  },
  emits: ['edit', 'family', 'tree', 'create'],

  setup(props, { emit }) {
    const ask = useAsk()
    // Suche und Seiten kommen vom Server – auch bei 50.000+ Personen werden nur 100 auf einmal geladen
    const { query, items: persons, total, loading, error, reload, loadMore } =
      usePagedSearch((q, opts) => searchPersons(q, opts), { pageSize: PAGE })

    async function remove(p) {
      const label = primaryNameLabel(p)
      if (!(await ask(t('person.list.deleteConfirm', { name: label })))) return
      await deletePerson(p.id)
      await reload()
    }

    onMounted(reload)
    watch(() => props.refresh, reload)

    const isSelected = p => props.selectedId != null && String(p.id) === String(props.selectedId)

    // Gewählte Person, die (noch) nicht in den geladenen Seiten steht, oben anheften
    const pinned = computed(() => {
      const sp = props.selectedPerson
      if (!sp || persons.value.some(isSelected)) return null
      const n  = sp.names?.[0] ?? {}
      const ev = type => sp.events?.find(e => e.event_type === type)
      return {
        id: sp.id, sex: sp.sex, restriction: sp.restriction,
        given_name: n.given_name, surname: n.surname, surname_prefix: n.surname_prefix, nickname: n.nickname,
        name_count: sp.names?.length ?? 1,
        birth_date: ev('BIRT')?.event_date, birth_place: ev('BIRT')?.event_place, death_date: ev('DEAT')?.event_date,
        photo_id: sp.media?.find(m => m.is_primary)?.id ?? null,
        _pinned: true,
      }
    })
    const rows = computed(() => pinned.value ? [pinned.value, ...persons.value] : persons.value)

    // Gewählte Person sichtbar machen
    const listEl = ref(null)
    watch(() => [props.selectedId, persons.value.length], async () => {
      await nextTick()
      listEl.value?.querySelector('.person-card.selected')?.scrollIntoView({ block: 'nearest' })
    })

    return { canEdit, t, PAGE, persons, rows, total, query, loading, error, remove, loadMore, emit, isSelected, listEl,
             primaryNameLabel, lifeSpan, sexClass, mediaThumbUrl }
  },
})
</script>

<template>
<div class="card">
  <div class="list-header">
    <h2 class="card-title">{{ t('person.list.title') }}</h2>
    <span v-if="total || query" class="list-count">
      {{ total === 1 ? t('person.list.totalOne') : t('person.list.total', { n: total.toLocaleString() }) }}
    </span>
  </div>

  <div class="list-toolbar">
    <IconField v-if="total || query" class="list-search">
      <InputIcon :class="['pi', loading ? 'pi-spin pi-spinner' : 'pi-search']" />
      <InputText v-model="query" type="search" :placeholder="t('person.list.searchServer')" fluid />
    </IconField>
    <Button v-if="canEdit" :label="t('person.list.new')" icon="pi pi-plus" size="small" @click="emit('create')" />
  </div>

  <p v-if="loading && !persons.length" class="muted">{{ t('common.loading') }}</p>
  <div v-else-if="error" class="alert alert-error">{{ t('person.list.loadError') }}</div>
  <p v-else-if="!persons.length && !query" class="muted">{{ t('person.list.empty') }}</p>
  <p v-else-if="!persons.length" class="muted">{{ t('person.list.noMatch') }}</p>

  <ul v-else ref="listEl" class="person-list">
    <li
      v-for="p in rows" :key="(p._pinned ? 'pin-' : '') + p.id"
      :class="['person-card', sexClass(p), { selected: isSelected(p), pinned: p._pinned }]"
      tabindex="0"
      @click="emit('edit', p)"
      @keydown.enter="emit('edit', p)"
    >
      <div :class="['avatar', { 'avatar-photo': p.photo_id }]" aria-hidden="true">
        <img v-if="p.photo_id" :src="mediaThumbUrl(p.photo_id)" alt="" loading="lazy" />
        <svg v-else viewBox="0 0 32 32" fill="currentColor"><circle cx="16" cy="12" r="6"/><path d="M4 32c0-7 5.4-11 12-11s12 4 12 11z"/></svg>
      </div>

      <div class="person-info">
        <div class="person-name">{{ primaryNameLabel(p) }}<i v-if="p.restriction" class="pi pi-lock lock-icon" :title="t('person.restriction.label') + ': ' + p.restriction" /></div>
        <div v-if="lifeSpan(p)" class="person-years">{{ lifeSpan(p) }}</div>
        <div v-if="p.birth_place || p.nickname || p.name_count > 1" class="name-detail">
          <span v-if="p.nickname">&bdquo;{{ p.nickname }}&ldquo;</span>
          <span v-if="p.nickname && p.birth_place"> · </span>
          <span v-if="p.birth_place">{{ p.birth_place }}</span>
          <span v-if="p.name_count > 1">
            · {{ t(p.name_count - 1 === 1 ? 'person.list.moreName' : 'person.list.moreNames', { n: p.name_count - 1 }) }}
          </span>
        </div>
      </div>

      <div class="row-actions" @click.stop>
        <button class="icon-btn" :title="t('tree.showInTree')" :aria-label="t('tree.showInTree')" @click="emit('tree', p)"><i class="pi pi-sitemap" /></button>
        <button v-if="canEdit" class="icon-btn" :title="t('person.list.relations')" :aria-label="t('person.list.relations')" @click="emit('family', p)"><i class="pi pi-users" /></button>
        <button v-if="canEdit" class="icon-btn danger" :title="t('action.delete')" :aria-label="t('action.delete')" @click="remove(p)"><i class="pi pi-trash" /></button>
      </div>
    </li>
    <li v-if="persons.length < total" class="list-more">
      <Button :label="t('list.loadMore', { n: Math.min(PAGE, total - persons.length), rest: (total - persons.length).toLocaleString() })"
        icon="pi pi-angle-down" text size="small" :loading="loading" @click="loadMore" />
    </li>
  </ul>
</div>

</template>
