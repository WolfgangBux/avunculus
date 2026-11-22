<script setup>
import { ref, reactive, computed, watch, provide, onMounted } from 'vue'
import Button from 'openvue/button'
import { fetchSurnameBranches } from '../api/surnames.js'
import { t } from '../i18n/index.js'
import SurnameBranchNode from './SurnameBranchNode.vue'

/**
 * Zweige eines Nachnamens: je Stammvater/-mutter (Träger, deren Eltern den Namen nicht tragen) die
 * Nachkommen, die den Namen weitertragen, mit Partnern; Kinder ohne den Namen als letzte Ebene.
 * Große Zweige starten zugeklappt bis zur zweiten Ebene.
 */
const props = defineProps({
  surname:    { type: String, required: true },
  selectedId: { type: [Number, String], default: null },
  refresh:    Number,
})
const emit = defineEmits(['edit'])

const OPEN_ALL_UP_TO = 150   // bis zu so vielen Trägern ist anfangs alles aufgeklappt

const data    = ref(null)
const loading = ref(false)
const error   = ref(false)
const open    = reactive(new Set())   // aufgeklappte Träger (IDs)
let seq = 0

const bearers = computed(() => new Set(data.value?.bearers ?? []))

async function load() {
  const my = ++seq
  loading.value = true
  try {
    const res = await fetchSurnameBranches(props.surname)
    if (my !== seq) return
    data.value  = res
    error.value = false
    open.clear()
    if (res.bearers.length <= OPEN_ALL_UP_TO) openAll()
    else for (const r of res.roots) open.add(r)   // Wurzeln auf: zweite Ebene sichtbar
  } catch {
    if (my === seq) error.value = true
  } finally {
    if (my === seq) loading.value = false
  }
}
onMounted(load)
watch(() => [props.surname, props.refresh], load)

function openAll()  { for (const id of data.value?.bearers ?? []) open.add(id) }
function closeAll() { open.clear() }

provide('surnameBranches', {
  data, bearers, open,
  selectedId: computed(() => props.selectedId),
  edit: id => emit('edit', { id }),
})
</script>

<template>
<div class="surname-branches">
  <div v-if="error" class="alert alert-error">{{ t('surnames.loadError') }}</div>
  <p v-else-if="loading && !data" class="muted">{{ t('common.loading') }}</p>
  <p v-else-if="data && !data.roots.length" class="muted">{{ t('surnames.noBranches') }}</p>
  <template v-else-if="data">
    <div class="branches-bar">
      <span class="muted">{{ t(data.roots.length === 1 ? 'surnames.branchOne' : 'surnames.branches', { n: data.roots.length.toLocaleString(), m: data.bearers.length.toLocaleString() }) }}</span>
      <span class="branches-actions">
        <Button :label="t('surnames.openAll')"  text size="small" @click="openAll" />
        <Button :label="t('surnames.closeAll')" text size="small" @click="closeAll" />
      </span>
    </div>
    <ul class="branch-tree">
      <SurnameBranchNode v-for="r in data.roots" :key="r" :id="r" :path="[]" />
    </ul>
  </template>
</div>
</template>
