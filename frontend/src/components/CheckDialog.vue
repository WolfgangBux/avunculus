<script setup>
import { ref, computed, watch } from 'vue'
import Dialog from 'openvue/dialog'
import Button from 'openvue/button'
import Select from 'openvue/select'
import { fetchChecks, CHECKS } from '../api/checks.js'
import { showChecks } from '../composables/checks.js'
import { currentTreeId } from '../trees.js'
import { nameLabel, lifeSpan, displayDate } from '../format.js'
import { t } from '../i18n/index.js'

/** Fehlersuche: unplausible Lebensdaten im aktuellen Baum. Klick öffnet die Person, das Symbol die Familie. */
const visible = showChecks

const result  = ref(null)
const loading = ref(false)
const error   = ref(false)
const check   = ref('all')   // 'all' statt '' – die Auswahlliste zeigt '' als „nichts gewählt“

async function load() {
  loading.value = true
  error.value   = false
  try   { result.value = await fetchChecks() }
  catch { error.value = true }
  finally { loading.value = false }
}
watch(visible, v => { if (v) { check.value = 'all'; load() } })
watch(currentTreeId, () => { result.value = null; if (visible.value) load() })

const counts = computed(() => result.value?.counts ?? {})
const total  = computed(() => Object.values(counts.value).reduce((a, b) => a + b, 0))

const checkOptions = computed(() => [
  { value: 'all', label: `${t('checks.all')} (${total.value.toLocaleString()})` },
  ...CHECKS.filter(c => counts.value[c]).map(c => ({ value: c, label: `${t('checks.' + c + '.title')} (${counts.value[c].toLocaleString()})` })),
])

const person = id => result.value?.persons?.[id]
const label  = id => {
  const p = person(id)
  if (!p) return '#' + id
  const span = lifeSpan(p)
  return span ? `${nameLabel(p)} (${span})` : nameLabel(p)
}

const items = computed(() => (result.value?.items ?? []).filter(i => check.value === 'all' || i.check === check.value))

function describe(i) {
  return t(`checks.${i.check}.text`, {
    other: i.other ? label(i.other) : '', age: i.age, days: i.days,
    a: displayDate(i.dates[0]), b: displayDate(i.dates[1]),
  })
}

function go(view, id) {
  location.hash = `#/${currentTreeId.value}/${view}/${id}`
  visible.value = false
}
</script>

<template>
  <Dialog v-model:visible="visible" modal :draggable="false" :header="t('checks.title')"
    :style="{ width: '46rem' }" :breakpoints="{ '760px': '95vw' }">

    <p class="muted dialog-intro">{{ t('checks.intro') }}</p>
    <div v-if="total" class="checks-toolbar">
      <Select v-model="check" :options="checkOptions" option-label="label" option-value="value" class="checks-filter" />
    </div>

    <p v-if="loading && !result" class="muted"><i class="pi pi-spin pi-spinner" /> {{ t('checks.running') }}</p>
    <div v-else-if="error" class="alert alert-error">{{ t('checks.loadError') }}</div>
    <p v-else-if="result && !total" class="checks-ok"><i class="pi pi-check-circle" /> {{ t('checks.none') }}</p>

    <ul v-else-if="result" class="checks-list">
      <li v-for="(i, n) in items" :key="n" class="check-item" tabindex="0"
        @click="go('persons', i.person)" @keydown.enter="go('persons', i.person)">
        <i class="pi pi-exclamation-triangle check-icon" aria-hidden="true" />
        <div class="check-main">
          <div class="check-title">{{ t(`checks.${i.check}.title`) }}</div>
          <div class="check-person">{{ label(i.person) }}</div>
          <div class="check-text">{{ describe(i) }}</div>
        </div>
        <Button v-if="i.family" icon="pi pi-users" text rounded size="small"
          :aria-label="t('checks.openFamily')" v-tooltip.top="t('checks.openFamily')" @click.stop="go('families', i.family)" />
      </li>
    </ul>
    <p v-if="result?.truncated" class="muted checks-truncated">{{ t('checks.truncated', { n: result.items.length.toLocaleString() }) }}</p>

    <template #footer>
      <Button :label="t('checks.rerun')" icon="pi pi-refresh" severity="secondary" text :loading="loading" class="checks-rerun" @click="load" />
      <Button :label="t('action.close')" severity="secondary" outlined @click="visible = false" />
    </template>
  </Dialog>
</template>

<style scoped>
.checks-toolbar { display: flex; margin-bottom: .6rem; }
.checks-filter { flex: 0 1 22rem; min-width: 0; }
.checks-ok { display: flex; align-items: center; gap: .5rem; }
.checks-ok .pi { color: var(--color-primary); }
.checks-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: .3rem; max-height: 60vh; overflow-y: auto; }
.check-item { display: flex; align-items: flex-start; gap: .6rem; padding: .45rem .6rem; cursor: pointer;
  border: 1px solid var(--color-border); border-radius: var(--radius-sm); background: var(--color-surface); }
.check-item:hover { border-color: var(--color-primary); background: var(--color-primary-soft); }
.check-icon { color: var(--color-primary); font-size: .85rem; margin-top: .2rem; }
.check-main { flex: 1; min-width: 0; }
.check-title { font-size: .75rem; color: var(--color-muted); }
.check-person { font-weight: 600; font-size: .88rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.check-text { font-size: .8rem; }
.checks-truncated { font-size: .8rem; margin-top: .5rem; }
.checks-rerun { margin-right: auto; }
</style>
