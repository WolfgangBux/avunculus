<script setup>
import { ref, watch } from 'vue'
import { fetchChanges, changeTime } from '../api/changes.js'
import { openChangeLog } from '../composables/changeLog.js'
import { canEdit } from '../auth.js'
import { t } from '../i18n/index.js'

/**
 * „Zuletzt geändert … von …“ unter einem Formular, mit Sprung ins gefilterte Änderungsprotokoll.
 * stamp: der geladene Datensatz – ändert er sich (z. B. nach dem Speichern), wird neu abgefragt.
 */
const props = defineProps({
  type:  { type: String, required: true },
  id:    { type: [Number, String], default: null },
  label: { type: String, default: '' },
  stamp: { default: null },
})

const last = ref(null)
const total = ref(0)
let seq = 0

async function load() {
  const my = ++seq
  last.value = null
  if (!canEdit.value || !props.id) return
  try {
    const res = await fetchChanges({ type: props.type, id: props.id, limit: 1 })
    if (my === seq) { last.value = res.items[0] ?? null; total.value = res.total }
  } catch { /* Protokoll ist Zusatzinfo – Fehler nicht melden */ }
}
watch(() => [props.type, props.id, props.stamp, canEdit.value], load, { immediate: true })
</script>

<template>
  <div v-if="last" class="last-change muted">
    <i class="pi pi-history" aria-hidden="true" />
    {{ t('changes.lastChange', { time: changeTime(last.created_at), user: last.user_name }) }}
    <button type="button" class="link-btn" @click="openChangeLog({ type, id, label })">
      {{ t('changes.history', { n: total }) }}
    </button>
  </div>
</template>

<style scoped>
.last-change { display: flex; align-items: center; flex-wrap: wrap; gap: .35rem; font-size: .75rem; margin-top: .75rem; }
.last-change .pi { font-size: .75rem; }
.link-btn { border: none; background: none; padding: 0; font: inherit; color: var(--color-primary); cursor: pointer; }
.link-btn:hover { text-decoration: underline; }
</style>
