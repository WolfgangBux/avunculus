<script setup>
import { ref } from 'vue'
import AutoComplete from 'openvue/autocomplete'
import Button from 'openvue/button'
import { searchPersons } from '../api/persons.js'
import { extractYear } from '../gedcom.js'
import { nameLabel } from '../format.js'
import { t } from '../i18n/index.js'

const props = defineProps({
  excludeIds:  { type: Array,  default: () => [] },
  placeholder: { type: String, default: '' },
})
const emit = defineEmits(['select', 'cancel'])

const selected    = ref(null)
const suggestions = ref([])
let   seq         = 0


function lifeSpan(p) {
  const b = extractYear(p.birth_date)
  const d = extractYear(p.death_date)
  return [b ? `* ${b}` : null, d ? `† ${d}` : null].filter(Boolean).join('  ')
}

// Vorschläge kommen vom Server (Namen und/oder Jahreszahl, z. B. „Müller 1879“)
async function search(event) {
  const my = ++seq
  try {
    const res = await searchPersons(event.query.trim(), { limit: 15, exclude: props.excludeIds })
    if (my === seq) suggestions.value = res.items
  } catch {
    if (my === seq) suggestions.value = []
  }
}

function onSelect(event) {
  emit('select', event.value)
  selected.value = null
}
</script>

<template>
  <div class="person-search">
    <AutoComplete
      v-model="selected"
      :suggestions="suggestions"
      :option-label="nameLabel"
      :placeholder="placeholder || t('person.search.placeholder')"
      :delay="250"
      :empty-search-message="t('person.search.noMatch')"
      dropdown
      fluid
      auto-focus
      complete-on-focus
      @complete="search"
      @option-select="onSelect"
    >
      <template #option="{ option }">
        <div :class="['search-option', 'sex-' + (option.sex || 'U')]">
          <span class="search-dot" />
          <span class="search-option-name">{{ nameLabel(option) }}</span>
          <span class="search-option-years">{{ lifeSpan(option) }}</span>
        </div>
      </template>
    </AutoComplete>
    <Button :label="t('action.cancel')" severity="secondary" text size="small" @click="emit('cancel')" />
  </div>
</template>

<style scoped>
.person-search { display: flex; gap: .4rem; align-items: center; }
.person-search > :first-child { flex: 1; }

.search-option { display: flex; align-items: center; gap: .5rem; width: 100%; }
.search-dot {
  width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0;
  background: var(--sex-fg, var(--sex-U-fg));
}
.search-option-name  { flex: 1; }
.search-option-years { font-size: .78rem; color: var(--color-muted); font-variant-numeric: tabular-nums; }
</style>
