<script setup>
import { ref, watch } from 'vue'
import AutoComplete from 'openvue/autocomplete'
import { searchPersons } from '../api/persons.js'
import { nameLabel, lifeSpan, sexClass } from '../format.js'

/**
 * Eingabefeld zur Auswahl einer Person per Server-Suche (für große Bäume).
 * v-model ist die Personen-ID ('' = keine); label zeigt den Namen einer bereits gewählten Person an.
 */
const props = defineProps({
  modelValue:  { type: [Number, String], default: '' },
  label:       { type: String, default: '' },
  excludeIds:  { type: Array, default: () => [] },
  placeholder: { type: String, default: '' },
})
const emit = defineEmits(['update:modelValue', 'update:label'])

const selected    = ref(null)
const suggestions = ref([])
let   seq         = 0

watch(() => [props.modelValue, props.label], ([id, label]) => {
  if (!id) selected.value = null
  else if (selected.value?.id !== Number(id)) selected.value = { id: Number(id), _label: label || `#${id}` }
  // Name einer vorbelegten Person kommt oft erst später
  else if (label && selected.value._label && selected.value._label !== label) selected.value = { ...selected.value, _label: label }
}, { immediate: true })

const labelOf = p => p?._label ?? nameLabel(p)

async function search(event) {
  const my = ++seq
  try {
    const res = await searchPersons(event.query.trim(), { limit: 15, exclude: props.excludeIds })
    if (my === seq) suggestions.value = res.items
  } catch {
    if (my === seq) suggestions.value = []
  }
}

function onSelect(e) { emit('update:modelValue', Number(e.value.id)); emit('update:label', labelOf(e.value)) }
function onClear()   { selected.value = null; emit('update:modelValue', '') }
</script>

<template>
  <AutoComplete
    v-model="selected"
    :suggestions="suggestions"
    :option-label="labelOf"
    :placeholder="placeholder"
    :delay="250"
    dropdown
    force-selection
    fluid
    @complete="search"
    @option-select="onSelect"
    @clear="onClear"
  >
    <template #option="{ option }">
      <div :class="['search-option', sexClass(option)]">
        <span class="search-dot" />
        <span class="search-option-name">{{ nameLabel(option) }}</span>
        <span class="search-option-years">{{ lifeSpan(option) }}</span>
      </div>
    </template>
  </AutoComplete>
</template>

<style scoped>
.search-option { display: flex; align-items: center; gap: .5rem; width: 100%; }
.search-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; background: var(--sex-fg, var(--sex-u-fg)); }
.search-option-name  { flex: 1; }
.search-option-years { font-size: .78rem; color: var(--color-muted); font-variant-numeric: tabular-nums; }
</style>
