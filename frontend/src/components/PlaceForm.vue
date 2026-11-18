<script setup>
import { ref, reactive, computed, watch } from 'vue'
import InputText from 'openvue/inputtext'
import Textarea from 'openvue/textarea'
import Select from 'openvue/select'
import Button from 'openvue/button'
import LastChange from './LastChange.vue'
import PlacePicker from './PlacePicker.vue'
import WikidataSearchDialog from './WikidataSearchDialog.vue'
import { updatePlace, createPlace, mergePlace, deletePlace, fetchWikidataPlace } from '../api/places.js'
import { useNotify, useAsk } from '../composables/feedback.js'
import { displayDate, nameLabel } from '../format.js'
import { t } from '../i18n/index.js'
import { canEdit } from '../auth.js'
import { currentTreeId } from '../trees.js'

/** Ort bearbeiten: Name, Oberort, Typ, Koordinaten, GOV, Wikidata, andere Namen; Unterorte, Zusammenführen, Löschen */
const props = defineProps({ place: { type: Object, required: true } })
const emit  = defineEmits(['saved', 'select', 'created', 'merged', 'deleted'])

const notify = useNotify()
const ask    = useAsk()

const FIELDS = ['name', 'place_type', 'latitude', 'longitude', 'gov_id', 'wikidata_id', 'notes']
const TYPES  = ['Staat', 'Land', 'Bundesland', 'Provinz', 'Regierungsbezirk', 'Bezirk', 'Landkreis', 'Amt',
                'Gemeinde', 'Stadt', 'Markt', 'Ort', 'Dorf', 'Weiler', 'Einöde', 'Ortsteil', 'Pfarrei', 'Kirche', 'Friedhof']

const form    = reactive({})
const names   = ref([])
const errors  = ref({})
const saving  = ref(false)
const fetching = ref(false)

function fill(p) {
  for (const f of FIELDS) form[f] = p[f] ?? ''
  // „48.728300“ → „48.7283“
  for (const f of ['latitude', 'longitude']) if (form[f] !== '') form[f] = String(Number(form[f]))
  names.value  = (p.names ?? []).map(n => ({ name: n.name, language: n.language ?? '', date_period: n.date_period ?? '' }))
  const parent = p.path?.length > 1 ? p.path[p.path.length - 2] : null
  parentId.value   = parent?.id ?? null
  parentName.value = p.full_name?.includes(', ') ? p.full_name.slice(p.full_name.indexOf(', ') + 2) : ''
  errors.value = {}
  newChild.value = ''
  mergeId.value = null
  mergeName.value = ''
}
const parentId   = ref(null)
const parentName = ref('')

const typeOptions = computed(() => [...new Set([...(form.place_type ? [form.place_type] : []), ...TYPES])])

// ── Koordinaten ───────────────────────────────────────────
const num = v => Number(String(v).trim().replace(',', '.'))
const hasCoords = computed(() => form.latitude !== '' && form.longitude !== ''
  && Number.isFinite(num(form.latitude)) && Number.isFinite(num(form.longitude)))

/** „48.7283, 13.6011“ (z. B. aus einer Karte kopiert) auf beide Felder verteilen */
function onCoordPaste(e) {
  const text = e.clipboardData?.getData('text') ?? ''
  const m = text.match(/(-?\d+(?:\.\d+)?)\s*[,; ]\s*(-?\d+(?:\.\d+)?)/)
  if (!m) return
  e.preventDefault()
  form.latitude  = m[1]
  form.longitude = m[2]
  errors.value = {}
}

const mapUrl = computed(() => {
  if (!hasCoords.value) return ''
  const lat = num(form.latitude), lon = num(form.longitude)
  return `https://www.openstreetmap.org/?mlat=${lat}&mlon=${lon}#map=14/${lat}/${lon}`
})

// ── Verknüpfungen ─────────────────────────────────────────
// Kennung oder eingefügter Link → Kennung (der Server prüft genauso)
const govId = computed(() => {
  const v = form.gov_id.trim()
  const m = v.match(/gov\.genealogy\.net\/.*?(?:item\/show\/|id=)([A-Za-z0-9_-]+)/i)
  return m ? m[1] : (/^[A-Za-z0-9_-]{2,60}$/.test(v) ? v : '')
})
const wikidataId = computed(() => form.wikidata_id.trim().match(/(?:^|[/=:])(Q\d{1,12})\b/i)?.[1]?.toUpperCase() ?? '')

const govUrl      = computed(() => govId.value ? `https://gov.genealogy.net/item/show/${govId.value}` : '')
const wikidataUrl = computed(() => wikidataId.value ? `https://www.wikidata.org/wiki/${wikidataId.value}` : '')
const showWdSearch = ref(false)
const wdContext    = computed(() => (props.place.path ?? []).slice(0, -1).map(p => p.name).reverse())

function normalizeLinks() {
  if (govId.value) form.gov_id = govId.value
  if (wikidataId.value) form.wikidata_id = wikidataId.value
}

/**
 * Angaben eines Wikidata-Objekts ins Formular übernehmen (gespeichert wird erst mit „Speichern“):
 * Kennung, Koordinaten und GOV-Kennung werden ersetzt, die Art nur ergänzt, Bezeichnungen in
 * anderen Sprachen kommen als andere Namen hinzu, soweit sie vom Namen abweichen und neu sind.
 */
async function applyWikidata(qid) {
  if (!qid) return
  fetching.value = true
  try {
    const wd = await fetchWikidataPlace(qid)
    const taken = [t('place.fields.wikidata')]
    form.wikidata_id = wd.id
    if (wd.latitude != null) {
      form.latitude  = String(+wd.latitude.toFixed(6))
      form.longitude = String(+wd.longitude.toFixed(6))
      taken.push(t('place.fields.coordinates'))
    }
    if (wd.gov_id && wd.gov_id !== govId.value) { form.gov_id = wd.gov_id; taken.push('GOV') }
    if (wd.type && !form.place_type) {
      // „Stadt in Deutschland“ → „Stadt“, „Ortsteil von …“ → „Ortsteil“
      form.place_type = wd.type.replace(/\s+(in|im|von|der|des)\s.*$/i, '').slice(0, 60)
      taken.push(t('place.fields.type'))
    }
    const known = new Set([form.name, ...names.value.map(n => n.name)].map(n => n.trim().toLowerCase()))
    const added = new Map()   // Name → Sprachen
    for (const l of wd.labels) {
      const key = l.name.trim().toLowerCase()
      if (known.has(key)) continue
      if (!added.has(key)) added.set(key, { name: l.name.trim(), langs: [] })
      added.get(key).langs.push(l.language)
    }
    for (const a of added.values()) names.value.push({ name: a.name, language: a.langs.join(', ').slice(0, 40), date_period: '' })
    if (added.size) taken.push(t('place.wikidata.namesTaken', { n: added.size }))
    errors.value = {}
    notify.success(t('place.wikidata.taken', { what: taken.join(', '), label: wd.label }))
  } catch {
    notify.error(t('place.wikidata.error'))
  } finally {
    fetching.value = false
  }
}

// ── Alternative Namen ─────────────────────────────────────
const addName    = () => names.value.push({ name: '', language: '', date_period: '' })
const removeName = i => names.value.splice(i, 1)

// ── Speichern ─────────────────────────────────────────────
async function submit() {
  normalizeLinks()
  if (!form.name.trim()) { errors.value = { name: 'required' }; return }
  saving.value = true
  try {
    const saved = await updatePlace(props.place.id, { ...form, parent_id: parentId.value, names: names.value })
    notify.success(t('place.saved', { name: saved.name }))
    emit('saved', saved)
  } catch (e) {
    if (e?.errors) errors.value = e.errors
    else notify.error(e?.error ?? t('action.saveError'))
  } finally {
    saving.value = false
  }
}

// ── Unterort anlegen, zusammenführen, löschen ─────────────
const newChild = ref('')
async function addChild() {
  const name = newChild.value.trim()
  if (!name) return
  try {
    const created = await createPlace({ name, parent_id: props.place.id })
    notify.success(t('place.created', { name: created.name }))
    emit('created', created)
  } catch (e) {
    notify.error(e?.error ?? t('action.saveError'))
  }
}

const mergeId   = ref(null)
const mergeName = ref('')
async function merge() {
  if (!mergeId.value) return
  const n = props.place.event_total + (props.place.other_total ?? 0)
  if (!(await ask(t('place.mergeConfirm', { name: props.place.full_name, target: mergeName.value, n: fmt(n) }),
                  { acceptLabel: t('place.merge'), danger: false }))) return
  try {
    const target = await mergePlace(props.place.id, mergeId.value)
    notify.success(t('place.merged', { name: target.full_name }))
    emit('merged', target)
  } catch (e) {
    notify.error(e?.error ?? t('action.saveError'))
  }
}

const deletable = computed(() => !props.place.event_total && !props.place.other_total && !props.place.children.length)
async function remove() {
  if (!(await ask(t('place.deleteConfirm', { name: props.place.full_name })))) return
  try {
    await deletePlace(props.place.id)
    notify.success(t('place.deleted', { name: props.place.name }))
    emit('deleted', props.place)
  } catch (e) {
    notify.error(e?.error ?? t('action.saveError'))
  }
}

const errorText = f => errors.value[f] ? t(`place.errors.${f}`) : ''

// ── Ereignisse an diesem Ort ──────────────────────────────
const eventLabel = e => {
  const key = e.kind === 'family' ? `family.eventTypes.${e.event_type}` : `person.eventTypes.${e.event_type}`
  const label = t(key)
  return label === key ? e.event_type : label.replace(/\s*\([A-Z]+\)$/, '')
}
const ownersLabel = e => e.persons.map(nameLabel).join(' ⚭ ') || '–'
// Ort des Ereignisses nur zeigen, wenn es ein Unterort ist
const subPlace = e => {
  const own = props.place.full_name
  return e.event_place.toLowerCase() === own.toLowerCase() ? '' : e.event_place.slice(0, e.event_place.length - own.length).replace(/,\s*$/, '')
}
// Person bzw. Familie in einem neuen Fenster öffnen (die App stellt die Ansicht aus der Adresse her)
const eventHref = e => `${location.pathname}#/${currentTreeId.value}/${e.kind === 'family' ? 'families' : 'persons'}/${e.owner_id}`
const fmt = n => n.toLocaleString()

// erst hier: fill() braucht alle obigen Zustände
watch(() => props.place, fill, { immediate: true })
</script>

<template>
  <div class="card place-form">
    <nav class="place-path" :aria-label="t('place.path')">
      <template v-for="(p, i) in place.path" :key="p.id">
        <i v-if="i" class="pi pi-angle-right" aria-hidden="true" />
        <button v-if="p.id !== place.id" type="button" class="place-path-link" @click="emit('select', p.id)">{{ p.name }}</button>
        <span v-else class="place-path-current">{{ p.name }}</span>
      </template>
    </nav>
    <div class="place-head">
      <h2 class="card-title">{{ place.name }}</h2>
      <Button v-if="canEdit && (deletable)" icon="pi pi-trash" :label="t('action.delete')" size="small" severity="danger" text @click="remove" />
    </div>

    <form :class="{ 'read-only': !canEdit }" novalidate @submit.prevent="submit">
      <div class="place-grid">
        <div class="form-group">
          <label for="place_name">{{ t('place.fields.name') }} *</label>
          <InputText id="place_name" v-model.trim="form.name" :invalid="!!errors.name" fluid @input="errors = {}" />
          <small v-if="errors.name" class="error-msg">{{ t('place.errors.name') }}</small>
        </div>
        <div class="form-group">
          <label for="place_type">{{ t('place.fields.type') }}</label>
          <Select id="place_type" v-model="form.place_type" :options="typeOptions" editable show-clear
            :placeholder="t('place.fields.typePlaceholder')" fluid />
        </div>
        <div class="form-group">
          <label for="place_lat" :title="t('place.coordHint')">
            {{ t('place.fields.latitude') }}
            <a v-if="mapUrl" :href="mapUrl" target="_blank" rel="noopener" class="place-ext" :title="t('place.showMap')">
              <i class="pi pi-map" /> {{ t('place.map') }}</a>
          </label>
          <InputText id="place_lat" v-model.trim="form.latitude" inputmode="decimal" placeholder="48.7283"
            :title="t('place.coordHint')" :invalid="!!errors.latitude" fluid @paste="onCoordPaste" @input="errors = {}" />
          <small v-if="errors.latitude" class="error-msg">{{ errorText('latitude') }}</small>
        </div>
        <div class="form-group">
          <label for="place_lon" :title="t('place.coordHint')">{{ t('place.fields.longitude') }}</label>
          <InputText id="place_lon" v-model.trim="form.longitude" inputmode="decimal" placeholder="13.6011"
            :title="t('place.coordHint')" :invalid="!!errors.longitude" fluid @paste="onCoordPaste" @input="errors = {}" />
          <small v-if="errors.longitude" class="error-msg">{{ errorText('longitude') }}</small>
        </div>

        <div class="form-group span-half">
          <label for="place_parent" :title="t('place.parentHint')">{{ t('place.fields.parent') }}</label>
          <PlacePicker v-model:place-id="parentId" v-model:place-name="parentName" input-id="place_parent"
            :allow-new="false" :exclude-ids="[place.id]" :placeholder="t('place.fields.parentNone')" />
        </div>
        <div class="form-group span-half">
          <label for="place_gov">{{ t('place.fields.gov') }}</label>
          <div class="repo-select-row">
            <InputText id="place_gov" v-model.trim="form.gov_id" :invalid="!!errors.gov_id" :title="t('place.linkHint')"
              :placeholder="t('place.fields.govPlaceholder')" fluid @blur="normalizeLinks" @input="errors = {}" />
            <a v-if="govUrl" :href="govUrl" target="_blank" rel="noopener" class="repo-link"
              :title="t('place.openGov')"><i class="pi pi-external-link" /></a>
          </div>
          <small v-if="errors.gov_id" class="error-msg">{{ errorText('gov_id') }}</small>
        </div>

        <div class="form-group span-all">
          <label for="place_wd">{{ t('place.fields.wikidata') }}</label>
          <div class="repo-select-row">
            <InputText id="place_wd" v-model.trim="form.wikidata_id" :invalid="!!errors.wikidata_id" :title="t('place.linkHint')"
              :placeholder="t('place.fields.wikidataPlaceholder')" fluid @blur="normalizeLinks" @input="errors = {}" />
            <a v-if="wikidataUrl" :href="wikidataUrl" target="_blank" rel="noopener" class="repo-link"
              :title="t('place.openWikidata')"><i class="pi pi-external-link" /></a>
            <Button v-if="canEdit" type="button" icon="pi pi-search" size="small" severity="secondary" outlined
              :label="t('place.searchWikidata')" :loading="fetching && !wikidataId" @click="showWdSearch = true" />
            <Button v-if="canEdit && (wikidataId)" type="button" icon="pi pi-download" size="small" severity="secondary" outlined
              :label="t('place.wikidata.fetch')" :loading="fetching" @click="applyWikidata(wikidataId)" />
          </div>
          <small v-if="errors.wikidata_id" class="error-msg">{{ errorText('wikidata_id') }}</small>
        </div>
      </div>
      <WikidataSearchDialog v-model:visible="showWdSearch" :query="place.name" :context="wdContext" @select="applyWikidata" />

      <fieldset class="place-compact">
        <legend>
          {{ t('place.sections.names') }}
          <Button v-if="canEdit" type="button" :label="t('place.names.add')" icon="pi pi-plus" size="small" text class="legend-btn" @click="addName" />
        </legend>
        <div v-for="(n, i) in names" :key="i" class="place-name-row">
          <InputText v-model="n.name" size="small" :placeholder="t('place.names.name')" :aria-label="t('place.names.name')" />
          <InputText v-model="n.language" size="small" :placeholder="t('place.names.language')" :aria-label="t('place.names.language')" />
          <InputText v-model="n.date_period" size="small" :placeholder="t('place.names.period')" :aria-label="t('place.names.period')" />
          <button v-if="canEdit" type="button" class="icon-btn danger" :title="t('action.delete')" :aria-label="t('action.delete')"
            @click="removeName(i)"><i class="pi pi-trash" /></button>
        </div>
      </fieldset>

      <fieldset class="place-compact">
        <legend>{{ t('person.sections.notes') }}</legend>
        <Textarea v-model="form.notes" rows="1" auto-resize :aria-label="t('person.notes.label')" fluid />
      </fieldset>

      <div v-if="canEdit" class="form-actions place-actions">
        <Button :label="t('action.reset')" severity="secondary" outlined @click="fill(place)" />
        <Button type="submit" icon="pi pi-check" :loading="saving"
          :label="saving ? t('action.saving') : t('action.saveChanges')" />
      </div>
    </form>

    <!-- Ab hier: Aktionen und Übersichten, die nicht über „Speichern“ laufen -->
    <fieldset class="place-section">
      <legend>{{ t('place.children', { n: place.children.length }) }}</legend>
      <div v-if="canEdit" class="place-add-row">
        <InputText v-model="newChild" size="small" :placeholder="t('place.newChildPlaceholder', { name: place.name })"
          :aria-label="t('place.newChild')" @keydown.enter.prevent="addChild" />
        <Button type="button" :label="t('place.newChild')" icon="pi pi-plus" size="small" outlined
          :disabled="!newChild.trim()" @click="addChild" />
      </div>
      <div v-if="place.children.length" class="place-children">
        <button v-for="c in place.children" :key="c.id" type="button" class="place-chip" @click="emit('select', c.id)">
          {{ c.name }}
        </button>
      </div>
    </fieldset>

    <fieldset class="place-section">
      <legend>{{ t('place.events', { n: fmt(place.event_total) }) }}</legend>
      <p v-if="!place.events.length" class="field-hint">{{ t('place.noEvents') }}</p>
      <ul v-else class="repo-sources">
        <li v-for="e in place.events" :key="e.kind + e.id">
          <a :href="eventHref(e)" target="_blank" rel="noopener" class="repo-source place-ev-link">
            <i :class="['pi', e.kind === 'family' ? 'pi-users' : 'pi-user']" />
            <span class="place-ev-type">{{ eventLabel(e) }}</span>
            <span class="repo-source-title">{{ ownersLabel(e) }}<span v-if="subPlace(e)" class="place-ev-sub"> · {{ subPlace(e) }}</span></span>
            <span class="repo-source-caln">{{ displayDate(e.event_date) }}</span>
            <i class="pi pi-external-link place-ev-ext" aria-hidden="true" />
          </a>
        </li>
      </ul>
      <p v-if="place.event_total > place.events.length" class="field-hint">
        {{ t('place.moreEvents', { n: fmt(place.events.length), total: fmt(place.event_total) }) }}
      </p>
      <p v-if="place.other_total" class="field-hint">{{ t('place.otherTrees', { n: fmt(place.other_total) }) }}</p>
    </fieldset>

    <fieldset v-if="canEdit" class="place-section">
      <legend>{{ t('place.mergeTitle') }}</legend>
      <div class="place-add-row">
        <PlacePicker v-model:place-id="mergeId" v-model:place-name="mergeName" :allow-new="false" size="small"
          :exclude-ids="[place.id]" :placeholder="t('place.mergePlaceholder')" />
        <Button type="button" :label="t('place.merge')" icon="pi pi-arrow-right-arrow-left" size="small" outlined
          :disabled="!mergeId" @click="merge" />
      </div>
      <small class="field-hint">{{ t('place.mergeHint') }}</small>
    </fieldset>
    <LastChange type="place" :id="place.id" :label="place.full_name" :stamp="place" />
  </div>
</template>
