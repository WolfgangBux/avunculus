<script setup>
import { ref, computed, watch } from 'vue'
import Button from 'openvue/button'
import { fetchPersonFamilies } from '../api/families.js'
import { lifeSpan, sexClass, assoRole, inverseAssoRole } from '../format.js'
import { extractYear } from '../gedcom.js'
import { t } from '../i18n/index.js'
import { canEdit } from '../auth.js'

/**
 * Alle Beziehungen einer Person auf einen Blick: Eltern, Geschwister, Partner mit Kindern.
 * Personen und Familien sind anklickbar (zum Bearbeiten).
 */
const props = defineProps({
  personId: { type: Number, required: true },
  person:   { type: Object, default: null },   // geladene Person: Ereignisse mit Beteiligten, associated_in
  refresh:  Number,
})
const emit = defineEmits(['edit-person', 'edit-family', 'manage'])

const families = ref([])
const loading  = ref(false)

async function load() {
  loading.value = true
  try   { families.value = await fetchPersonFamilies(props.personId) }
  catch { families.value = [] }
  finally { loading.value = false }
}
watch(() => [props.personId, props.refresh], load, { immediate: true })

const birthFamilies = computed(() => families.value.filter(f => f.person_role === 'CHIL'))
const ownFamilies   = computed(() => families.value.filter(f => f.person_role !== 'CHIL'))

const parentsOf  = f => f.members.filter(m => m.role !== 'CHIL')
const childrenOf = f => f.members.filter(m => m.role === 'CHIL')
const partnersOf = f => f.members.filter(m => m.role !== 'CHIL' && m.person_id !== props.personId)
const siblingsOf = f => childrenOf(f).filter(m => m.person_id !== props.personId)
const ownPedigree = f => f.members.find(m => m.role === 'CHIL' && m.person_id === props.personId)?.pedigree

const years = m => lifeSpan(m)
const marriageYear = f => {
  const marr = (f.events ?? []).find(e => e.event_type === 'MARR')
  return marr ? (extractYear(marr.event_date) ?? '') : null
}
const parentLabel = m => t(m.role === 'HUSB' ? 'family.father' : m.role === 'WIFE' ? 'family.mother' : 'family.partner')

// Rückrichtung: wo diese Person bei anderen eingetragen ist – an deren Ereignissen, direkt an der Person
// oder an Familienereignissen (scope = family: Trauzeuge bei der Heirat von X & Y)
const reverse = computed(() => props.person?.associated_in ?? [])

// Verknüpfte Personen: direkt an der Person (Pate, Weggefährte …), aus den eigenen Ereignissen und
// Personen, bei denen diese Person eingetragen ist (A hat B als „Patensohn“ → bei B: A als Taufpate).
// Gegenseitige Einträge (beide Richtungen in der GEDCOM-Datei) erscheinen nur einmal.
const eventPersons = computed(() => {
  const own = props.person?.associations ?? []
  const ownIds = new Set(own.map(a => a.person_id))
  return [
    ...own,
    ...(props.person?.events ?? []).flatMap(e =>
      (e.associations ?? []).map(a => ({ ...a, event_type: e.event_type, event_date: e.event_date }))),
    ...reverse.value.filter(a => a.scope === 'person' && !ownIds.has(a.person_id))
      .map(a => ({ ...a, reverseLabel: inverseAssoRole(a) ?? t('relations.listedAs', { role: assoRole(a) ?? t('relations.involved') }) })),
  ]
})
// „Beteiligt bei“: nur Ereignisse anderer Personen bzw. Familien
const associatedIn = computed(() => reverse.value.filter(a => a.scope !== 'person'))

function roleAt(a) {
  if (a.reverseLabel) return a.reverseLabel
  const role  = assoRole(a)
  if (!a.event_type) return role ?? t('relations.linked')
  const year  = extractYear(a.event_date)
  const event = t((a.scope === 'family' ? 'family.eventTypes.' : 'person.eventTypes.') + a.event_type) + (year ? ' ' + year : '')
  return t('relations.roleAt', { role: role ?? t('relations.involved'), event })
}
const openAssociated = a => a.scope === 'family' ? emit('edit-family', a.family_id) : emit('edit-person', a.person_id)

const isEmpty = computed(() => !loading.value && !families.value.length
  && !eventPersons.value.length && !associatedIn.value.length)
</script>

<template>
  <div class="relations">
    <div class="relations-head">
      <span class="relations-title">{{ t('relations.title') }}</span>
      <Button v-if="canEdit" :label="t('relations.manage')" icon="pi pi-users" text size="small" @click="emit('manage')" />
    </div>

    <p v-if="isEmpty" class="field-hint">{{ t('relations.none') }}</p>

    <!-- Herkunft: Eltern und Geschwister (je Elternfamilie, z. B. auch Adoptiveltern) -->
    <div v-for="f in birthFamilies" :key="'b' + f.id" class="relations-row">
      <span class="relations-label">
        {{ t('relations.parents') }}
        <span v-if="ownPedigree(f) && ownPedigree(f) !== 'BIRTH'" class="pedigree-tag">{{ t('family.pedigree.' + ownPedigree(f)) }}</span>
      </span>
      <div class="relations-chips">
        <button type="button" v-for="m in parentsOf(f)" :key="m.person_id" :class="['rel-chip', sexClass(m)]"
          :title="parentLabel(m)" @click="emit('edit-person', m.person_id)">
          <span class="rel-name">{{ m.display_name }}</span>
          <span v-if="years(m)" class="rel-years">{{ years(m) }}</span>
        </button>
        <span v-if="!parentsOf(f).length" class="muted">–</span>
        <button type="button" class="rel-family" :title="t('relations.openFamily')" @click="emit('edit-family', f.id)">
          <i class="pi pi-sitemap" /> {{ t('relations.family') }}
        </button>
      </div>
      <template v-if="siblingsOf(f).length">
        <span class="relations-label">{{ t('relations.siblings') }}</span>
        <div class="relations-chips">
          <button type="button" v-for="m in siblingsOf(f)" :key="m.person_id" :class="['rel-chip', 'rel-chip-sm', sexClass(m)]"
            @click="emit('edit-person', m.person_id)">
            <span class="rel-name">{{ m.display_name }}</span>
            <span v-if="years(m)" class="rel-years">{{ years(m) }}</span>
          </button>
        </div>
      </template>
    </div>

    <!-- Personen aus Ereignissen: beteiligt an meinen Ereignissen bzw. ich bei ihren -->
    <div v-if="eventPersons.length || associatedIn.length" class="relations-row">
      <template v-if="eventPersons.length">
        <span class="relations-label">{{ t('relations.inEvents') }}</span>
        <div class="relations-chips">
          <button type="button" v-for="(a, i) in eventPersons" :key="'e' + i" :class="['rel-chip', 'rel-chip-sm', sexClass(a)]"
            :title="a.notes || ''" @click="emit('edit-person', a.person_id)">
            <span class="rel-name">{{ a.display_name }}</span>
            <span class="rel-years">{{ roleAt(a) }}</span>
          </button>
        </div>
      </template>
      <template v-if="associatedIn.length">
        <span class="relations-label">{{ t('relations.associatedIn') }}</span>
        <div class="relations-chips">
          <button type="button" v-for="(a, i) in associatedIn" :key="'r' + i" :class="['rel-chip', 'rel-chip-sm', sexClass(a)]"
            :title="a.notes || ''" @click="openAssociated(a)">
            <i v-if="a.scope === 'family'" class="pi pi-users" />
            <span class="rel-name">{{ a.display_name }}</span>
            <span class="rel-years">{{ roleAt(a) }}</span>
          </button>
        </div>
      </template>
    </div>

    <!-- Eigene Familien: Partner und Kinder -->
    <div v-for="f in ownFamilies" :key="'o' + f.id" class="relations-row">
      <span class="relations-label">
        {{ t('relations.partner') }}
        <span v-if="marriageYear(f) !== null" class="rel-marriage"><i class="pi pi-heart" /> {{ marriageYear(f) }}</span>
      </span>
      <div class="relations-chips">
        <button type="button" v-for="m in partnersOf(f)" :key="m.person_id" :class="['rel-chip', sexClass(m)]"
          @click="emit('edit-person', m.person_id)">
          <span class="rel-name">{{ m.display_name }}</span>
          <span v-if="years(m)" class="rel-years">{{ years(m) }}</span>
        </button>
        <span v-if="!partnersOf(f).length" class="muted">{{ t('relations.noPartner') }}</span>
        <button type="button" class="rel-family" :title="t('relations.openFamily')" @click="emit('edit-family', f.id)">
          <i class="pi pi-sitemap" /> {{ t('relations.family') }}
        </button>
      </div>
      <template v-if="childrenOf(f).length">
        <span class="relations-label">{{ t('relations.children') }}</span>
        <div class="relations-chips">
          <button type="button" v-for="m in childrenOf(f)" :key="m.person_id" :class="['rel-chip', 'rel-chip-sm', sexClass(m)]"
            @click="emit('edit-person', m.person_id)">
            <span class="rel-name">{{ m.display_name }}</span>
            <span v-if="years(m)" class="rel-years">{{ years(m) }}</span>
            <span v-if="m.pedigree && m.pedigree !== 'BIRTH'" class="pedigree-tag">{{ t('family.pedigree.' + m.pedigree) }}</span>
          </button>
        </div>
      </template>
    </div>
  </div>
</template>
