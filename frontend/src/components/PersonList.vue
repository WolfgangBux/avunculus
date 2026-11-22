<script>
import { ref, computed, watch, onMounted, nextTick, defineComponent } from 'vue'
import { searchPersons, deletePerson } from '../api/persons.js'
import { nameLabel as primaryNameLabel, lifeSpan, sexClass } from '../format.js'
import { t } from '../i18n/index.js'
import { useAsk } from '../composables/feedback.js'
import { usePagedSearch } from '../composables/pagedSearch.js'
import { mediaThumbUrl } from '../api/media.js'
import { canEdit } from '../auth.js'
import { currentTreeId } from '../trees.js'
import { personListNav as nav } from '../composables/personListNav.js'
import SurnameIndex from './SurnameIndex.vue'
import SurnameBranches from './SurnameBranches.vue'
import SelectButton from 'openvue/selectbutton'
import IconField from 'openvue/iconfield'
import InputIcon from 'openvue/inputicon'
import InputText from 'openvue/inputtext'
import Button from 'openvue/button'

const PAGE = 100

/** Ab so vielen Personen beginnt die Liste (ohne gemerkte Wahl) mit der Nachnamen-Übersicht */
const SURNAMES_FROM = 1000
const MODE_KEY = 'avunculus.personListMode'

export default defineComponent({
  components: { IconField, InputIcon, InputText, Button, SelectButton, SurnameIndex, SurnameBranches },
  props: {
    refresh:    Number,
    selectedId:     { type: [Number, String], default: null },
    selectedPerson: { type: Object, default: null },   // vollständig geladene Person (falls nicht in der Liste)
  },
  emits: ['edit', 'family', 'tree', 'create'],

  setup(props, { emit }) {
    const ask = useAsk()
    // Suche und Seiten kommen vom Server – auch bei 50.000+ Personen werden nur 100 auf einmal geladen
    const { query, items: persons, total, start, loading, error, reload, loadMore, loadBefore, loadAround } =
      usePagedSearch((q, opts) => searchPersons(q, { ...opts, surname: nav.surname }), { pageSize: PAGE })

    // ── Liste oder Nachnamen-Übersicht ──
    // Neu in diesem Baum: mit gewählter Person (Adresse, Stammbaum) die Liste, sonst die gemerkte Wahl,
    // sonst nach Größe des Baums. Ein gewählter Nachname filtert die Liste (oder zeigt seine Zweige).
    if (nav.tree !== currentTreeId.value) {
      let stored = null
      try { stored = localStorage.getItem(MODE_KEY) } catch { /* kein Storage */ }
      Object.assign(nav, { tree: currentTreeId.value, letter: null, surname: null, show: 'persons',
                           mode: props.selectedId != null ? 'list' : (stored === 'list' || stored === 'surnames' ? stored : null) })
    }
    // Von außen vorgegebene Suche (Wolke in der Auswertung) übernehmen
    if (nav.query != null) { query.value = nav.query; nav.query = null }
    const modeOptions = computed(() => [
      { value: 'list',     label: t('surnames.modeList') },
      { value: 'surnames', label: t('surnames.modeSurnames') },
    ])
    const showOptions = computed(() => [
      { value: 'persons',  label: t('surnames.showPersons') },
      { value: 'branches', label: t('surnames.showBranches') },
    ])
    const mode = computed({
      get: () => nav.mode ?? (total.value > SURNAMES_FROM ? 'surnames' : 'list'),
      set: v => {
        if (!v) return
        nav.mode = v
        try { localStorage.setItem(MODE_KEY, v) } catch { /* kein Storage */ }
        if (v === 'list' && nav.surname !== null) setSurname(null)
      },
    })
    function setSurname(s) {
      // Die automatische Wahl hängt an der Anzahl – die gefilterte Liste ist kleiner, daher jetzt festhalten
      if (s !== null) nav.mode ??= 'surnames'
      nav.surname = s
      nav.show = 'persons'
      reload()
    }
    // Suchen heißt Personen finden: aus der Übersicht in die Liste wechseln
    watch(query, q => { if (q.trim() && mode.value === 'surnames' && nav.surname === null) mode.value = 'list' })
    const showIndex    = computed(() => mode.value === 'surnames' && nav.surname === null)
    const showBranches = computed(() => nav.surname !== null && nav.show === 'branches')
    const surnameLabel = computed(() => nav.surname === '' ? t('surnames.none') : nav.surname)

    async function remove(p) {
      const label = primaryNameLabel(p)
      if (!(await ask(t('person.list.deleteConfirm', { name: label })))) return
      await deletePerson(p.id)
      await reload()
    }

    const isSelected = p => props.selectedId != null && String(p.id) === String(props.selectedId)

    // Gewählte Person (z. B. aus dem Stammbaum) an ihrer alphabetischen Stelle zeigen: steht sie nicht in den
    // geladenen Zeilen, die Seite laden, auf der sie steht – davor und dahinter lässt sich weiterblättern
    const listEl = ref(null)
    async function showSelected({ force = false } = {}) {
      if (props.selectedId == null) return force ? reload() : undefined
      const jump = force || !persons.value.some(isSelected)
      if (jump) await loadAround(props.selectedId)
      await nextTick()
      // nach dem Nachladen in die Mitte; ein Klick in der Liste soll sie nicht verschieben
      listEl.value?.querySelector('.person-card.selected')?.scrollIntoView({ block: jump ? 'center' : 'nearest' })
    }
    onMounted(() => showSelected({ force: true }))
    watch(() => props.selectedId, () => showSelected())
    watch(() => props.refresh, () => showSelected({ force: true }))   // nach Speichern: neuer Name → neue Stelle

    // Nur wenn sie nicht zur Suche passt: gewählte Person oben anheften
    const pinned = computed(() => {
      const sp = props.selectedPerson
      if (!sp || loading.value || persons.value.some(isSelected)) return null
      const n  = sp.names?.[0] ?? {}
      const ev = type => sp.events?.find(e => e.event_type === type)
      return {
        id: sp.id, sex: sp.sex, restriction: sp.restriction,
        given_name: n.given_name, surname: n.surname, surname_prefix: n.surname_prefix, nickname: n.nickname,
        name_count: sp.names?.length ?? 1,
        birth_date: ev('BIRT')?.event_date, birth_place: ev('BIRT')?.event_place, death_date: ev('DEAT')?.event_date,
        photo_id: sp.media?.find(m => m.is_primary)?.id ?? null,
        photo_crop: sp.media?.find(m => m.is_primary)?.crop ?? null,
        _pinned: true,
      }
    })
    const rows = computed(() => pinned.value ? [pinned.value, ...persons.value] : persons.value)

    const rest = computed(() => total.value - start.value - persons.value.length)

    return { canEdit, t, PAGE, persons, rows, total, start, rest, query, loading, error, remove, loadMore, loadBefore, emit, isSelected, listEl,
             nav, mode, modeOptions, showOptions, setSurname, showIndex, showBranches, surnameLabel,
             primaryNameLabel, lifeSpan, sexClass, mediaThumbUrl }
  },
})
</script>

<template>
<div class="card">
  <div class="list-header">
    <h2 class="card-title">{{ t('person.list.title') }}</h2>
    <SelectButton v-model="mode" :options="modeOptions" option-value="value" option-label="label" :allow-empty="false"
      size="small" class="list-mode" :aria-label="t('surnames.mode')" />
    <span v-if="(total || query) && !showIndex && !showBranches && nav.surname === null" class="list-count">
      {{ total === 1 ? t('person.list.totalOne') : t('person.list.total', { n: total.toLocaleString() }) }}
    </span>
  </div>

  <!-- Gewählter Nachname: Liste gefiltert oder seine Zweige -->
  <div v-if="nav.surname !== null" class="surname-filter">
    <button type="button" class="icon-btn" :title="t('surnames.back')" :aria-label="t('surnames.back')" @click="setSurname(null)">
      <i class="pi pi-arrow-left" />
    </button>
    <strong class="surname-filter-name">{{ surnameLabel }}
      <span v-if="!showBranches && !query" class="list-count">· {{ total.toLocaleString() }}</span></strong>
    <SelectButton v-if="nav.surname !== ''" v-model="nav.show" :options="showOptions" option-value="value" option-label="label"
      :allow-empty="false" size="small" />
  </div>

  <template v-if="!showBranches">
  <div class="list-toolbar">
    <IconField v-if="total || query" class="list-search">
      <InputIcon :class="['pi', loading ? 'pi-spin pi-spinner' : 'pi-search']" />
      <InputText v-model="query" type="search" :placeholder="t('person.list.searchServer')" fluid />
    </IconField>
    <Button v-if="canEdit" :label="t('person.list.new')" icon="pi pi-plus" size="small" @click="emit('create')" />
  </div>

  </template>

  <SurnameIndex v-if="showIndex" v-model:letter="nav.letter" :refresh="refresh" @pick="setSurname" />
  <SurnameBranches v-else-if="showBranches" :surname="nav.surname" :selected-id="selectedId" :refresh="refresh"
    @edit="p => emit('edit', p)" />

  <template v-if="!showIndex && !showBranches">
  <p v-if="loading && !persons.length" class="muted">{{ t('common.loading') }}</p>
  <div v-else-if="error" class="alert alert-error">{{ t('person.list.loadError') }}</div>
  <p v-else-if="!persons.length && !query" class="muted">{{ t('person.list.empty') }}</p>
  <p v-else-if="!persons.length" class="muted">{{ t('person.list.noMatch') }}</p>

  <ul v-else ref="listEl" class="person-list">
    <li v-if="start > 0" class="list-more">
      <Button :label="t('list.loadBefore', { n: Math.min(PAGE, start), rest: start.toLocaleString() })"
        icon="pi pi-angle-up" text size="small" :loading="loading" @click="loadBefore" />
    </li>
    <li
      v-for="p in rows" :key="(p._pinned ? 'pin-' : '') + p.id"
      :class="['person-card', sexClass(p), { selected: isSelected(p), pinned: p._pinned }]"
      tabindex="0"
      @click="emit('edit', p)"
      @keydown.enter="emit('edit', p)"
    >
      <div :class="['avatar', { 'avatar-photo': p.photo_id }]" aria-hidden="true">
        <img v-if="p.photo_id" :src="mediaThumbUrl(p.photo_id, p.photo_crop)" alt="" loading="lazy" />
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
    <li v-if="rest > 0" class="list-more">
      <Button :label="t('list.loadMore', { n: Math.min(PAGE, rest), rest: rest.toLocaleString() })"
        icon="pi pi-angle-down" text size="small" :loading="loading" @click="loadMore" />
    </li>
  </ul>
  </template>
</div>

</template>
