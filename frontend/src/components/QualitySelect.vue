<script setup>
import { computed } from 'vue'
import Select from 'openvue/select'
import { t } from '../i18n/index.js'

/** Verlässlichkeit einer Quellenangabe (GEDCOM QUAY): 3 = Primärquelle … 0 = unzuverlässig; null = keine Angabe */
const model = defineModel({ type: [Number, null], default: null })
defineProps({ size: { type: String, default: undefined } })

const options = computed(() => [3, 2, 1, 0].map(q => ({ value: q, label: '★'.repeat(q) + '☆'.repeat(3 - q) + '  ' + t('person.citations.quality.' + q) })))
</script>

<template>
  <Select v-model="model" :options="options" option-label="label" option-value="value" :size="size"
    :placeholder="t('person.citations.quality.none')" show-clear fluid />
</template>
