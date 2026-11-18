<script setup>
// Verknüpfte Personen, die nicht an ein Ereignis gebunden sind (INDI.ASSO): Pate, Vormund, Weggefährte …
import { ref, computed } from 'vue'
import Button from 'openvue/button'
import AssociationFields from './AssociationFields.vue'
import { t } from '../i18n/index.js'
import { canEdit } from '../auth.js'
import { assoRole } from '../format.js'
import { blankAssociation } from '../personModel.js'

const associations = defineModel({ type: Array, required: true })
const expanded = defineModel('expanded', { type: Object, default: () => ({}) })   // Leseansicht je Index
const props = defineProps({ personId: { type: Number, default: null } })
defineEmits(['go-person'])

const open       = ref(null)   // Index in Bearbeitung
const excludeIds = computed(() => props.personId ? [props.personId] : [])
const roleLabel  = a => assoRole(a) ?? t('person.associations.roleNone')
const toggle     = ai => { expanded.value = { ...expanded.value, [ai]: !expanded.value[ai] } }

function add() { associations.value.push({ ...blankAssociation(), notes: '' }); open.value = associations.value.length - 1 }
function remove(i) { associations.value.splice(i, 1); open.value = null; expanded.value = {} }
</script>

<template>
  <fieldset v-if="canEdit || associations.length">
    <legend>{{ t('person.sections.associations') }}</legend>
    <template v-for="(asso, ai) in associations" :key="ai">
      <!-- eingeklappt: Rolle · Name · Notiz -->
      <div v-if="open !== ai" class="name-entry name-entry-line">
        <div class="name-line" role="button" tabindex="0" :aria-expanded="!!expanded[ai]"
          @click="toggle(ai)" @keydown.enter="toggle(ai)">
          <span class="name-type-badge">{{ roleLabel(asso) }}</span>
          <span class="name-line-text">{{ asso.person_label || t('person.associations.personNone') }}</span>
          <span v-if="asso.notes && !expanded[ai]" class="muted asso-line-note" :title="asso.notes">{{ asso.notes }}</span>
          <span class="name-line-actions" @click.stop>
            <Button v-if="canEdit" icon="pi pi-pencil" text rounded size="small"
              :aria-label="t('action.edit')" v-tooltip.top="t('action.edit')" @click="open = ai" />
            <Button v-if="canEdit" icon="pi pi-trash" text rounded size="small" severity="danger"
              :aria-label="t('action.remove')" v-tooltip.top="t('action.remove')" @click="remove(ai)" />
            <i :class="['pi', expanded[ai] ? 'pi-chevron-up' : 'pi-chevron-down', 'event-chevron']" />
          </span>
        </div>
        <!-- Leseansicht: Person (anklickbar), Rolle, Notiz vollständig -->
        <div v-if="expanded[ai]" class="asso-view">
          <dl class="view-fields">
            <dt>{{ t('person.associations.personLabel') }}</dt>
            <dd>
              <button v-if="asso.person_id" type="button" class="rel-chip rel-chip-sm" @click="$emit('go-person', asso.person_id)">
                <span class="rel-name">{{ asso.person_label || '–' }}</span>
              </button>
              <template v-else>–</template>
            </dd>
            <dt>{{ t('person.associations.roleLabel') }}</dt><dd>{{ roleLabel(asso) }}</dd>
            <template v-if="asso.notes"><dt>{{ t('person.associations.notesLabel') }}</dt><dd class="view-notes">{{ asso.notes }}</dd></template>
          </dl>
        </div>
      </div>
      <div v-else class="sub-card person-cit">
        <AssociationFields :association="asso" :exclude-ids="excludeIds" with-notes @remove="remove(ai)">
          <div class="sub-wide asso-done">
            <Button :label="t('person.nameFields.done')" icon="pi pi-check" size="small" outlined @click="open = null" />
          </div>
        </AssociationFields>
      </div>
    </template>
    <p v-if="!associations.length" class="field-hint">{{ t('person.associationsHint') }}</p>
    <Button v-if="canEdit" :label="t('person.associations.add')" icon="pi pi-users" text size="small" class="slot-add" @click="add" />
  </fieldset>
</template>
