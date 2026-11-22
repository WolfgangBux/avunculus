<script setup>
// Stand des automatischen Speicherns: gespeichert, wird gespeichert, Eingaben prüfen, Fehler (mit „Erneut versuchen“)
import { computed } from 'vue'
import { t } from '../i18n/index.js'

const props = defineProps({
  status:     { type: String, required: true },   // saved | pending | saving | invalid | error
  isNew:      { type: Boolean, default: false },  // noch nicht angelegt: Hinweis statt „Gespeichert“
  incomplete: { type: String, default: '' },      // Hinweis für einen neuen Datensatz, was noch fehlt
})
const emit = defineEmits(['retry'])

const shown = computed(() => props.isNew && ['saved', 'invalid'].includes(props.status) ? 'incomplete' : props.status)
const info  = computed(() => ({
  saved:      { icon: 'pi-check',                text: t('autosave.saved') },
  pending:    { icon: 'pi-spin pi-spinner',      text: t('autosave.saving') },
  saving:     { icon: 'pi-spin pi-spinner',      text: t('autosave.saving') },
  invalid:    { icon: 'pi-exclamation-circle',   text: t('autosave.invalid') },
  incomplete: { icon: 'pi-info-circle',          text: props.incomplete },
  error:      { icon: 'pi-exclamation-triangle', text: t('autosave.error') },
}[shown.value]))
</script>

<template>
  <span v-if="info.text" :class="['save-status', 'save-status-' + shown]" role="status">
    <i :class="['pi', info.icon]" /> {{ info.text }}
    <button v-if="status === 'error'" type="button" class="save-retry" @click="emit('retry')">{{ t('autosave.retry') }}</button>
  </span>
</template>
