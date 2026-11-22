<script setup>
import { computed } from 'vue'
import { factLines } from './reportFormat.js'
import { t } from '../../i18n/index.js'

/** Kurzdaten einer Person (*, ~, ⚭, †, ▭, Beruf) als Zeilen */
const props = defineProps({
  person:   { type: Object, default: null },
  marriage: { type: Object, default: null },   // { date, place } – z. B. Heirat der Eltern in der Ahnentafel
})
const lines = computed(() => factLines(props.person, { marriage: props.marriage }))
</script>

<template>
  <p v-if="person?.private" class="report-facts muted">{{ t('reports.privateHint') }}</p>
  <ul v-else-if="lines.length" class="report-facts">
    <li v-for="l in lines" :key="l.key" :title="l.label">
      <span class="report-sym" :aria-label="l.label">{{ l.symbol || l.label + ':' }}</span> {{ l.text }}
    </li>
  </ul>
</template>
