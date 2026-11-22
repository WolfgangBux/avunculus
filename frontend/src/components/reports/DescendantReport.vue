<script setup>
import { computed, watch } from 'vue'
import { fetchDescendantReport } from '../../api/reports.js'
import { useReportData, nameLabel, nameWithSpan, datePlace, SYMBOLS } from './reportFormat.js'
import FactList from './FactList.vue'
import SymbolLegend from './SymbolLegend.vue'
import { t } from '../../i18n/index.js'

/**
 * Nachkommenbericht, nummeriert nach d'Aboville (1, 1.2, 1.2.3 …): je Nachkomme die Kurzdaten,
 * darunter jede Ehe mit Partner; die Kinder folgen eingerückt. Wer über zwei Linien abstammt,
 * wird nur beim ersten Mal ausgeführt.
 */
const props = defineProps({ params: { type: Object, required: true } })
const emit  = defineEmits(['title'])

const { data, error } = useReportData(() => fetchDescendantReport(props.params.person, props.params.gen))
const person = id => data.value?.persons?.[id]

watch(data, d => d && emit('title', t('reports.titles.descendants', { name: nameLabel(person(d.root)) })))

const rows = computed(() => {
  const d = data.value
  if (!d) return []
  const out  = []
  const seen = new Map()
  const walk = (pid, num, depth) => {
    const first = !seen.has(pid)
    if (first) seen.set(pid, num)
    const unions = first ? (d.unions[pid] ?? []) : []
    out.push({ pid, num, depth, sameAs: first ? null : seen.get(pid), unions })
    let i = 0
    for (const u of unions) for (const c of u.children) walk(c, `${num}.${++i}`, depth + 1)
  }
  walk(d.root, '1', 0)
  return out
})
/** „21.2.1591 in Alburg mit“ – der Name des Partners folgt fett */
function marriageText(u) {
  const when = u.marriage ? datePlace(u.marriage.date, u.marriage.place) : ''
  return [when, u.partner_id ? t('reports.with') : t('reports.with') + ' ' + t('reports.unknownPartner')].filter(Boolean).join(' ')
}
const count = computed(() => new Set(rows.value.slice(1).map(r => r.pid)).size)
</script>

<template>
  <p v-if="error" class="report-error">{{ t('reports.loadError') }}</p>
  <p v-else-if="!data" class="muted"><i class="pi pi-spin pi-spinner" /> {{ t('reports.loading') }}</p>
  <template v-else>
    <p class="report-summary">{{ t('reports.descendantSummary', { n: count, gens: data.generations }) }}</p>
    <p v-if="data.truncated" class="report-warning">{{ t('reports.truncatedDescendants') }}</p>
    <div class="report-entries">
      <article v-for="r in rows" :key="r.num" class="report-entry"
        :style="{ marginLeft: Math.min(r.depth, 8) * 1.1 + 'rem' }">
        <div class="report-num report-num-wide">{{ r.num }}</div>
        <div>
          <div class="report-name">{{ nameLabel(person(r.pid)) }}</div>
          <p v-if="r.sameAs" class="report-facts muted">{{ t('reports.seeNumber', { n: r.sameAs }) }}</p>
          <template v-else>
            <FactList :person="person(r.pid)" />
            <p v-for="u in r.unions" :key="u.family_id" class="report-union">
              <span class="report-sym">{{ SYMBOLS.marriage }}</span>
              {{ marriageText(u) }} <strong v-if="u.partner_id">{{ nameWithSpan(person(u.partner_id)) }}</strong>
              <span v-if="u.children.length" class="muted">{{ ' · ' + t('reports.childCount', { n: u.children.length }) }}</span>
            </p>
          </template>
        </div>
      </article>
    </div>
    <SymbolLegend />
  </template>
</template>
