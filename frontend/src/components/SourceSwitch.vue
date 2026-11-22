<script setup>
import { t } from '../i18n/index.js'

/** Umschalter Quellen | Archive im Kopf der beiden Listen (sie teilen sich einen Tab) */
defineProps({ active: { type: String, required: true } })   // 'sources' | 'repositories'
const emit = defineEmits(['switch'])
const items = [{ view: 'sources', label: 'nav.sources' }, { view: 'repositories', label: 'nav.repositories' }]
</script>

<template>
  <div class="list-switch" role="tablist">
    <button v-for="i in items" :key="i.view" type="button" role="tab" :aria-selected="active === i.view"
      :class="['list-switch-btn', { active: active === i.view }]" @click="active !== i.view && emit('switch', i.view)">
      {{ t(i.label) }}
    </button>
  </div>
</template>
