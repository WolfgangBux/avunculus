<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { fetchSurnameIndex, fetchSurnamesByLetter } from '../api/surnames.js'
import { t } from '../i18n/index.js'

/**
 * Nachnamen-Übersicht: Anfangsbuchstaben mit Anzahl Personen, ohne gewählten Buchstaben die häufigsten
 * Namen als Wolke, sonst alle Namen des Buchstabens. Ein Klick auf einen Namen meldet ihn (pick);
 * '' steht für „ohne Nachnamen“.
 */
const props = defineProps({
  letter:  { type: String, default: null },   // v-model:letter
  refresh: Number,
})
const emit = defineEmits(['update:letter', 'pick'])

const index    = ref(null)     // { initials, none, cloud }
const surnames = ref([])       // Namen des gewählten Buchstabens
const loading  = ref(false)
const error    = ref(false)
let seq = 0

async function loadIndex() {
  try   { index.value = await fetchSurnameIndex(); error.value = false }
  catch { error.value = true }
}

async function loadLetter() {
  const my = ++seq
  surnames.value = []
  if (!props.letter) return
  loading.value = true
  try {
    const res = await fetchSurnamesByLetter(props.letter)
    if (my === seq) { surnames.value = res.surnames; error.value = false }
  } catch {
    if (my === seq) error.value = true
  } finally {
    if (my === seq) loading.value = false
  }
}

onMounted(() => { loadIndex(); loadLetter() })
watch(() => props.letter, loadLetter)
watch(() => props.refresh, () => { loadIndex(); loadLetter() })   // nach Speichern: Namen und Zahlen können sich ändern

const choose = l => emit('update:letter', props.letter === l ? null : l)

// Wolke: Schriftgröße logarithmisch nach Anzahl
const cloud = computed(() => {
  const list = index.value?.cloud ?? []
  if (!list.length) return []
  const logs = list.map(s => Math.log(s.count))
  const min = Math.min(...logs), span = Math.max(...logs) - min || 1
  return list.map((s, i) => ({ ...s, size: (0.8 + ((logs[i] - min) / span) * 1.1).toFixed(2) + 'rem' }))
})

const fmt = n => n.toLocaleString()
</script>

<template>
<div class="surname-index">
  <div v-if="error" class="alert alert-error">{{ t('surnames.loadError') }}</div>
  <p v-else-if="!index" class="muted">{{ t('common.loading') }}</p>
  <p v-else-if="!index.initials.length && !index.none" class="muted">{{ t('person.list.empty') }}</p>

  <template v-else>
    <div class="surname-letters" role="toolbar" :aria-label="t('surnames.letters')">
      <button v-for="i in index.initials" :key="i.letter" type="button"
        :class="['surname-letter', { active: letter === i.letter }]" :aria-pressed="letter === i.letter"
        :title="t(i.count === 1 ? 'person.list.totalOne' : 'person.list.total', { n: fmt(i.count) })" @click="choose(i.letter)">
        {{ i.letter }}
      </button>
      <button v-if="index.none" type="button" class="surname-letter surname-none"
        :title="t(index.none === 1 ? 'person.list.totalOne' : 'person.list.total', { n: fmt(index.none) })" @click="emit('pick', '')">
        {{ t('surnames.none') }}
      </button>
    </div>

    <!-- Namen des gewählten Buchstabens -->
    <template v-if="letter">
      <p v-if="loading && !surnames.length" class="muted">{{ t('common.loading') }}</p>
      <ul v-else class="surname-list">
        <li v-for="s in surnames" :key="s.surname">
          <button type="button" class="surname-row" @click="emit('pick', s.surname)">
            <span class="surname-name">{{ s.surname }}</span>
            <span class="surname-count">{{ fmt(s.count) }}</span>
          </button>
        </li>
      </ul>
    </template>

    <!-- Ohne Buchstaben: häufigste Namen -->
    <template v-else-if="cloud.length">
      <h3 class="surname-subtitle">{{ t('surnames.cloud') }}</h3>
      <div class="surname-cloud">
        <button v-for="s in cloud" :key="s.surname" type="button" class="surname-cloud-item" :style="{ fontSize: s.size }"
          :title="t(s.count === 1 ? 'person.list.totalOne' : 'person.list.total', { n: fmt(s.count) })"
          @click="emit('pick', s.surname)">{{ s.surname }}</button>
      </div>
    </template>
  </template>
</div>
</template>
