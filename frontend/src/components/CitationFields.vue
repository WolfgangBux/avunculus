<script setup>
// Eingabefelder einer Quellenangabe (bearbeitet das übergebene Objekt direkt).
// Slot: weitere Inhalte unter den Feldern, z. B. „Fertig“
import InputText from 'openvue/inputtext'
import Textarea from 'openvue/textarea'
import Button from 'openvue/button'
import QualitySelect from './QualitySelect.vue'
import SourcePicker from './SourcePicker.vue'
import { t } from '../i18n/index.js'
import { canEdit } from '../auth.js'

defineProps({
  citation: { type: Object, required: true },
  idPrefix: { type: String, required: true },   // eindeutige IDs für die Labels
})
defineEmits(['remove'])
</script>

<template>
  <div class="sub-grid">
    <div class="form-group">
      <label>{{ t('person.citations.sourceLabel') }}</label>
      <SourcePicker v-model:source-id="citation.source_id" v-model:label="citation.source_label" />
    </div>
    <div class="form-group">
      <label :for="idPrefix + 'page'">{{ t('person.citations.pageLabel') }}</label>
      <InputText :id="idPrefix + 'page'" v-model.trim="citation.page" :placeholder="t('person.citations.pagePlaceholder')" fluid />
    </div>
    <Button v-if="canEdit" icon="pi pi-trash" severity="danger" text rounded class="sub-remove"
      :aria-label="t('action.remove')" v-tooltip.top="t('action.remove')" @click="$emit('remove')" />
    <div class="form-group">
      <label>{{ t('person.citations.quality.label') }}</label>
      <QualitySelect v-model="citation.quality" />
    </div>
    <div class="form-group sub-wide">
      <label :for="idPrefix + 'url'">{{ t('person.citations.urlLabel') }}</label>
      <InputText :id="idPrefix + 'url'" v-model.trim="citation.url" type="url" placeholder="https://…" fluid />
    </div>
    <div class="form-group sub-wide">
      <label :for="idPrefix + 'tran'">{{ t('person.citations.transcriptionLabel') }}</label>
      <Textarea :id="idPrefix + 'tran'" v-model="citation.transcription" rows="1" auto-resize
        :placeholder="t('person.citations.transcriptionPlaceholder')" fluid />
    </div>
    <slot />
  </div>
</template>
