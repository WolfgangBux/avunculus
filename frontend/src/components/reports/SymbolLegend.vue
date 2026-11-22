<script setup>
import { SYMBOLS } from './reportFormat.js'
import { t } from '../../i18n/index.js'
</script>

<template>
  <p class="report-legend">
    <span v-for="(sym, key) in SYMBOLS" :key="key">{{ sym }} {{ t('reports.facts.' + key) }}</span>
  </p>
</template>
