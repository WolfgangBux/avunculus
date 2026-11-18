<script setup>
// Namen einer Person: Hauptname (erster) und weitere Namen; je einer zum Bearbeiten aufgeklappt
import { computed } from 'vue'
import InputText from 'openvue/inputtext'
import Select from 'openvue/select'
import Button from 'openvue/button'
import { t } from '../i18n/index.js'
import { canEdit } from '../auth.js'
import { NAME_TYPE_VALUES, blankName, fullName } from '../personModel.js'

const names = defineModel({ type: Array, required: true })
const open  = defineModel('open', { default: null })   // Index des aufgeklappten Namens
defineProps({
  errors:  { type: Array, default: () => [] },   // je Name { given_name?, surname? }
  editing: { type: Boolean, default: false },    // bestehende Person (Hauptname lässt sich zuklappen)
})
const emit = defineEmits(['reordered'])

const nameTypeOptions = computed(() => NAME_TYPE_VALUES.map(v => ({ value: v, label: t('person.nameTypes.' + v) })))

function addName()      { names.value.push(blankName()); open.value = names.value.length - 1 }
function removeName(i)  { names.value.splice(i, 1); open.value = null }
function makePrimary(i) {
  const [n] = names.value.splice(i, 1)
  names.value.unshift(n)
  open.value = null
  emit('reordered')   // Fehlermeldungen beziehen sich auf die alte Reihenfolge
}
</script>

<template>
  <fieldset>
    <legend>{{ t('person.sections.names') }}</legend>
    <div v-for="(name, i) in names" :key="i"
      :class="['name-entry', { 'name-entry-primary': i === 0 && open === i, 'name-entry-line': open !== i }]">

      <!-- Name eingeklappt: eine Zeile (Hauptname mit Stern) -->
      <div v-if="open !== i" :class="['name-line', { 'name-line-primary': i === 0 }]" role="button" tabindex="0"
        @click="open = i" @keydown.enter="open = i">
        <i v-if="i === 0" class="pi pi-star-fill name-primary-star" :title="t('person.nameFields.primary')" />
        <span class="name-type-badge">{{ t('person.nameTypes.' + name.name_type) }}</span>
        <span class="name-line-text">{{ fullName(name) || t('person.nameFields.empty') }}</span>
        <span class="name-line-actions" @click.stop>
          <Button v-if="canEdit && (i > 0)" icon="pi pi-star" text rounded size="small" severity="secondary"
            :aria-label="t('person.nameFields.makePrimary')" v-tooltip.top="t('person.nameFields.makePrimary')" @click="makePrimary(i)" />
          <Button icon="pi pi-pencil" text rounded size="small"
            :aria-label="t('action.edit')" v-tooltip.top="t('action.edit')" @click="open = i" />
          <Button v-if="canEdit && (names.length > 1)" icon="pi pi-trash" text rounded size="small" severity="danger"
            :aria-label="t('action.remove')" v-tooltip.top="t('action.remove')" @click="removeName(i)" />
        </span>
      </div>

      <!-- Hauptname bzw. aufgeklappter Name: kompakte Eingabe in zwei Zeilen -->
      <template v-else>
        <div v-if="i === 0" class="name-primary-label">{{ t('person.nameFields.primary') }}</div>
        <div class="name-grid">
          <div class="form-group">
            <label :for="'npfx' + i" :title="t('person.nameFields.prefix')">{{ t('person.nameFields.short.prefix') }}</label>
            <InputText :id="'npfx' + i" v-model="name.name_prefix" :placeholder="t('person.nameFields.prefixPlaceholder')" fluid />
          </div>
          <div class="form-group">
            <label :for="'givn' + i" :title="t('person.nameFields.givenName')">{{ t('person.nameFields.short.given') }}{{ i === 0 ? ' *' : '' }}</label>
            <InputText :id="'givn' + i" v-model="name.given_name" :invalid="!!errors[i]?.given_name" :placeholder="t('person.nameFields.givenNamePlaceholder')" fluid />
          </div>
          <div class="form-group">
            <label :for="'spfx' + i" :title="t('person.nameFields.surnamePrefix')">{{ t('person.nameFields.short.spfx') }}</label>
            <InputText :id="'spfx' + i" v-model="name.surname_prefix" :placeholder="t('person.nameFields.surnamePrefixPlaceholder')" fluid />
          </div>
          <div class="form-group">
            <label :for="'surn' + i" :title="t('person.nameFields.surname')">{{ t('person.nameFields.short.surname') }}{{ i === 0 ? ' *' : '' }}</label>
            <InputText :id="'surn' + i" v-model="name.surname" :invalid="!!errors[i]?.surname" :placeholder="t('person.nameFields.surnamePlaceholder')" fluid />
          </div>
          <div class="form-group">
            <label :for="'nsfx' + i" :title="t('person.nameFields.suffix')">{{ t('person.nameFields.short.suffix') }}</label>
            <InputText :id="'nsfx' + i" v-model="name.name_suffix" :placeholder="t('person.nameFields.suffixPlaceholder')" fluid />
          </div>
        </div>
        <small v-if="errors[i]?.given_name" class="error-msg">{{ errors[i].given_name }}</small>

        <div class="name-grid-2">
          <div class="form-group">
            <label :title="t('person.nameFields.typeLabel')">{{ t('person.nameFields.short.type') }}</label>
            <Select v-model="name.name_type" :options="nameTypeOptions" option-label="label" option-value="value" fluid />
          </div>
          <div class="form-group">
            <label :for="'nick' + i" :title="t('person.nameFields.nickname')">{{ t('person.nameFields.short.nick') }}</label>
            <InputText :id="'nick' + i" v-model="name.nickname" :placeholder="t('person.nameFields.nicknamePlaceholder')" fluid />
          </div>
          <div class="name-grid-actions">
            <template v-if="i > 0">
              <Button v-if="canEdit" icon="pi pi-star" text rounded severity="secondary"
                :aria-label="t('person.nameFields.makePrimary')" v-tooltip.top="t('person.nameFields.makePrimary')" @click="makePrimary(i)" />
              <Button v-if="canEdit" icon="pi pi-trash" text rounded severity="danger"
                :aria-label="t('action.remove')" v-tooltip.top="t('action.remove')" @click="removeName(i)" />
              <Button :label="t('person.nameFields.done')" icon="pi pi-check" size="small" outlined @click="open = null" />
            </template>
            <template v-else>
              <Button v-if="canEdit && (names.length > 1)" icon="pi pi-trash" text rounded severity="danger"
                :aria-label="t('action.remove')" v-tooltip.top="t('action.remove')" @click="removeName(i)" />
              <Button v-if="editing" :label="t('person.nameFields.done')" icon="pi pi-check" size="small" outlined @click="open = null" />
            </template>
          </div>
        </div>
      </template>
    </div>
    <Button v-if="canEdit" :label="t('person.nameFields.addBtn')" icon="pi pi-plus" text size="small" @click="addName" />
  </fieldset>
</template>
