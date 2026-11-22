<script setup>
import { ref, computed, watch } from 'vue'
import Dialog from 'openvue/dialog'
import Button from 'openvue/button'
import Select from 'openvue/select'
import InputText from 'openvue/inputtext'
import PersonPicker from './PersonPicker.vue'
import { fetchFamily, fetchPersonFamilies } from '../api/families.js'
import { fetchPerson } from '../api/persons.js'
import { listName } from '../personModel.js'
import { currentTreeId } from '../trees.js'
import { displayDate } from '../format.js'
import { t } from '../i18n/index.js'
import { showReports, reportStart, reportUrl, REPORT_KINDS, PERSON_REPORTS, FAMILY_REPORTS, GENERATIONS } from '../composables/reports.js'

/**
 * Bericht wählen und einstellen (Person, Generationen, Familie, Zeitraum, Ort). „Öffnen“ zeigt die
 * Druckansicht in einem neuen Tab; von dort drucken oder als PDF speichern.
 */
const visible = showReports

const kind        = ref('ancestors')
const person      = ref('')
const personLabel = ref('')
const family      = ref(null)
const families    = ref([])   // Familien der gewählten Person, in denen sie Partner ist
const generations = ref(5)
const from        = ref('')
const to          = ref('')
const place       = ref('')

const kindOptions = computed(() => REPORT_KINDS.map(k => ({ value: k, label: t('reports.kinds.' + k), group: groupOf(k) })))
const groupOf     = k => PERSON_REPORTS.includes(k) || FAMILY_REPORTS.includes(k) ? 'sheets' : 'lists'
const kindGroups  = computed(() => ['sheets', 'lists'].map(g => ({ label: t('reports.groupLabels.' + g), items: kindOptions.value.filter(o => o.group === g) })))

const needsPerson = computed(() => PERSON_REPORTS.includes(kind.value) || FAMILY_REPORTS.includes(kind.value))
const isFamily    = computed(() => FAMILY_REPORTS.includes(kind.value))
const gen         = computed(() => GENERATIONS[kind.value] ?? null)
const genOptions  = computed(() => gen.value ? Array.from({ length: gen.value.max - 1 }, (_, i) => i + 2) : [])

watch(visible, async v => {
  if (!v) return
  const s = reportStart.value
  kind.value = s.kind
  person.value = s.person ?? ''
  personLabel.value = ''
  family.value = null
  // Familie vorgegeben (Familien-Ansicht): einen Partner als Person nehmen, die Familie vorwählen
  if (s.family) {
    try {
      const f = await fetchFamily(s.family)
      const partner = f.members?.find(m => m.role !== 'CHIL')
      if (partner) { person.value = partner.person_id; personLabel.value = partner.display_name }
      await loadFamilies()
      family.value = s.family
    } catch { /* dann eben von Hand wählen */ }
  }
  // Name der vorbelegten Person für das Auswahlfeld
  if (person.value && !personLabel.value) {
    const id = person.value
    fetchPerson(id).then(p => { if (person.value === id) personLabel.value = p.private ? t('privacy.person') : listName(p) }).catch(() => {})
  }
})

watch(kind, k => { if (GENERATIONS[k]) generations.value = GENERATIONS[k].def })
watch(currentTreeId, () => { person.value = ''; family.value = null })

// Familien der Person für das Familienblatt
watch([person, isFamily], () => { if (isFamily.value) loadFamilies() })
let seq = 0
async function loadFamilies() {
  const my = ++seq
  if (!person.value) { families.value = []; family.value = null; return }
  try {
    const list = await fetchPersonFamilies(person.value)
    if (my !== seq) return
    families.value = list.filter(f => f.person_role !== 'CHIL').map(f => ({ value: f.id, label: familyLabel(f) }))
    if (!families.value.some(f => f.value === family.value)) family.value = families.value[0]?.value ?? null
  } catch {
    if (my === seq) families.value = []
  }
}
function familyLabel(f) {
  const partners = f.members.filter(m => m.role !== 'CHIL' && m.person_id !== Number(person.value)).map(m => m.display_name)
  const marr     = f.events?.find(e => e.event_type === 'MARR')
  return [t('reports.familyWith', { name: partners.join(', ') || t('reports.unknownPartner') }),
          marr?.event_date ? '⚭ ' + displayDate(marr.event_date) : ''].filter(Boolean).join(' · ')
}

const year = v => (/^\d{3,4}$/.test(String(v).trim()) ? String(v).trim() : '')
const canOpen = computed(() => isFamily.value ? !!family.value : needsPerson.value ? !!person.value : true)

function open() {
  if (!canOpen.value) return
  const params = isFamily.value ? { family: family.value }
               : needsPerson.value ? { person: person.value, gen: gen.value ? generations.value : '' }
               : { from: year(from.value), to: year(to.value), place: place.value.trim() }
  window.open(reportUrl(kind.value, params), '_blank')
}
</script>

<template>
  <Dialog v-model:visible="visible" modal :draggable="false" :header="t('reports.title')"
    :style="{ width: '34rem' }" :breakpoints="{ '600px': '95vw' }">
    <p class="muted dialog-intro">{{ t('reports.intro') }}</p>

    <div class="form-row report-form-row">
      <div class="form-group">
        <label for="report-kind">{{ t('reports.kind') }}</label>
        <Select v-model="kind" input-id="report-kind" :options="kindGroups" option-group-label="label" option-group-children="items"
          option-label="label" option-value="value" fluid />
      </div>
    </div>

    <template v-if="needsPerson">
      <div class="form-row report-form-row">
        <div class="form-group">
          <label>{{ t('reports.person') }}</label>
          <PersonPicker v-model="person" v-model:label="personLabel" :placeholder="t('kinship.choose')" />
        </div>
      </div>
      <div v-if="gen" class="form-row report-form-row">
        <div class="form-group">
          <label for="report-gen">{{ t('reports.generations') }}</label>
          <Select v-model="generations" input-id="report-gen" :options="genOptions" fluid />
        </div>
      </div>
      <div v-if="isFamily" class="form-row report-form-row">
        <div class="form-group">
          <label for="report-family">{{ t('reports.family') }}</label>
          <Select v-model="family" input-id="report-family" :options="families" option-label="label" option-value="value"
            :placeholder="person ? t('reports.noFamilies') : t('reports.choosePersonFirst')" :disabled="!families.length" fluid />
        </div>
      </div>
    </template>

    <template v-else>
      <div class="form-row">
        <div class="form-group">
          <label for="report-from">{{ t('reports.yearFrom') }}</label>
          <InputText id="report-from" v-model="from" inputmode="numeric" placeholder="1700" />
        </div>
        <div class="form-group">
          <label for="report-to">{{ t('reports.yearTo') }}</label>
          <InputText id="report-to" v-model="to" inputmode="numeric" placeholder="1900" />
        </div>
      </div>
      <div class="form-row report-form-row">
        <div class="form-group">
          <label for="report-place">{{ t('reports.placeLabel') }}</label>
          <InputText id="report-place" v-model="place" :placeholder="t('reports.placePlaceholder')" @keydown.enter="open" />
        </div>
      </div>
    </template>

    <template #footer>
      <Button :label="t('action.cancel')" severity="secondary" text @click="visible = false" />
      <Button :label="t('reports.open')" icon="pi pi-external-link" :disabled="!canOpen" @click="open" />
    </template>
  </Dialog>
</template>

<style scoped>
.report-form-row { grid-template-columns: 1fr; }
</style>
