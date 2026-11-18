<script setup>
import { computed } from 'vue'
import { locale } from '../i18n/index.js'

/**
 * Rangliste als waagrechte Balken (eine Datenreihe, Akzentfarbe): Bezeichnung, Balken, Anzahl.
 * items: [{ label, count, title? }] – bereits absteigend sortiert.
 */
const props = defineProps({
  items:   { type: Array, default: () => [] },
  columns: { type: Boolean, default: false },   // breite Karte: zweispaltig
})
const max = computed(() => Math.max(1, ...props.items.map(i => i.count)))
</script>

<template>
  <ol v-if="items.length" :class="['rank-list', { 'rank-list-cols': columns }]">
    <li v-for="(i, n) in items" :key="n" :title="i.title || i.label">
      <span class="rank-label">{{ i.label }}</span>
      <span class="rank-track"><span class="rank-fill" :style="{ width: (100 * i.count / max) + '%' }" /></span>
      <span class="rank-value">{{ i.count.toLocaleString(locale) }}</span>
    </li>
  </ol>
  <p v-else class="muted">–</p>
</template>
