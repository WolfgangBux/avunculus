<script setup>
// Eingabefelder einer verknüpften Person (ASSO): Person, Rolle, ggf. eigene Rolle und Notiz.
// Bearbeitet das übergebene Objekt direkt. Slot: weitere Inhalte unter den Feldern, z. B. „Fertig“
import { computed } from 'vue'
import InputText from 'openvue/inputtext'
import Select from 'openvue/select'
import Textarea from 'openvue/textarea'
import Button from 'openvue/button'
import PersonPicker from './PersonPicker.vue'
import { t } from '../i18n/index.js'
import { canEdit } from '../auth.js'
import { ROLE_VALUES } from '../personModel.js'

defineProps({
  association: { type: Object, required: true },
  excludeIds:  { type: Array, default: () => [] },   // nicht auswählbar (die Person selbst)
  withNotes:   { type: Boolean, default: false },
})
defineEmits(['remove'])
const roleOptions = computed(() => ROLE_VALUES.map(v => ({ value: v, label: t('person.associations.roles.' + v) })))
</script>

<template>
  <div class="sub-grid">
    <div class="form-group">
      <label>{{ t('person.associations.personLabel') }}</label>
      <PersonPicker v-model="association.person_id" v-model:label="association.person_label"
        :exclude-ids="excludeIds" :placeholder="t('person.associations.personNone')" />
    </div>
    <div class="form-group">
      <label>{{ t('person.associations.roleLabel') }}</label>
      <Select v-model="association.role" :options="roleOptions" option-label="label" option-value="value"
        :placeholder="t('person.associations.roleNone')" filter show-clear fluid />
    </div>
    <Button v-if="canEdit" icon="pi pi-trash" severity="danger" text rounded class="sub-remove"
      :aria-label="t('action.remove')" v-tooltip.top="t('action.remove')" @click="$emit('remove')" />
    <div v-if="association.role === 'OTHER'" class="form-group sub-wide">
      <label>{{ t('person.associations.rolePhraseLabel') }}</label>
      <InputText v-model.trim="association.role_phrase" :placeholder="t('person.associations.rolePhrasePlaceholder')" fluid />
    </div>
    <div v-if="withNotes" class="form-group sub-wide">
      <label>{{ t('person.associations.notesLabel') }}</label>
      <Textarea v-model="association.notes" rows="1" auto-resize fluid />
    </div>
    <slot />
  </div>
</template>
