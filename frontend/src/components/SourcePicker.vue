<script setup>
import { ref, watch } from 'vue'
import AutoComplete from 'openvue/autocomplete'
import { searchSources, sourceLabel } from '../api/sources.js'
import { t } from '../i18n/index.js'

/**
 * Auswahl einer Quelle per Server-Suche (große Bäume haben Tausende Quellen).
 *   v-model:source-id – ID der gewählten Quelle (null = keine)
 *   v-model:label     – Anzeigename (Kurztitel bzw. Titel), kommt mit der Quellenangabe vom Server
 * Tippen sucht nur; die Auswahl ändert sich erst beim Wählen eines Treffers oder beim Leeren des Felds.
 */
const props = defineProps({
  sourceId: { type: [Number, String], default: null },
  label:    { type: String, default: '' },
  size:     { type: String, default: undefined },
  inputId:  { type: String, default: undefined },
})
const emit = defineEmits(['update:sourceId', 'update:label'])

const LIMIT       = 30
const text        = ref(props.label ?? '')
const suggestions = ref([])
let   seq         = 0

watch(() => props.label, v => { text.value = v ?? '' })

async function search(event) {
  const my = ++seq
  try {
    const res = await searchSources(event.query.trim(), { limit: LIMIT })
    if (my === seq) suggestions.value = res.items
  } catch {
    if (my === seq) suggestions.value = []
  }
}

function clear() {
  text.value = ''
  emit('update:sourceId', null)
  emit('update:label', '')
}
function onInput(v) {
  if (v == null || v === '') return clear()
  if (typeof v === 'string') text.value = v
}
function onSelect(e) {
  text.value = sourceLabel(e.value)
  emit('update:sourceId', e.value.id)
  emit('update:label', text.value)
}
// Getippter Suchtext ohne Auswahl: wieder die gewählte Quelle zeigen
function onBlur() { text.value = props.label ?? '' }

const meta = s => [s.abbreviation && s.abbreviation !== s.title ? s.title : null, s.author,
  [s.repository_name, s.call_number].filter(Boolean).join(', ')].filter(Boolean).join(' · ')
</script>

<template>
  <AutoComplete
    :model-value="text"
    :suggestions="suggestions"
    :option-label="sourceLabel"
    :placeholder="t('person.citations.sourceNone')"
    :input-id="inputId"
    :size="size"
    :delay="200"
    dropdown
    :show-clear="!!sourceId"
    fluid
    @update:model-value="onInput"
    @complete="search"
    @option-select="onSelect"
    @clear="clear"
    @blur="onBlur"
  >
    <template #option="{ option }">
      <div class="source-option">
        <i class="pi pi-book" />
        <span class="source-option-text">
          <strong>{{ sourceLabel(option) }}</strong>
          <span v-if="meta(option)" class="source-option-meta">{{ meta(option) }}</span>
        </span>
      </div>
    </template>
  </AutoComplete>
</template>

<style scoped>
.source-option { display: flex; align-items: center; gap: .5rem; width: 100%; min-width: 0; }
.source-option .pi { color: var(--color-primary); font-size: .8rem; flex-shrink: 0; }
.source-option-text { display: flex; flex-direction: column; min-width: 0; }
.source-option-text > * { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.source-option-meta { font-size: .75rem; color: var(--color-muted); }
</style>
