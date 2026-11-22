<script setup>
import { computed, watch } from 'vue'
import { fetchFamilyReport } from '../../api/reports.js'
import { useReportData, nameLabel, nameWithSpan, datePlace } from './reportFormat.js'
import FactList from './FactList.vue'
import SymbolLegend from './SymbolLegend.vue'
import { t } from '../../i18n/index.js'

/** Familienblatt: beide Partner mit Eltern, die Ereignisse der Familie, die Kinder mit ihren Ehen */
const props = defineProps({ params: { type: Object, required: true } })
const emit  = defineEmits(['title'])

const { data, error } = useReportData(() => fetchFamilyReport(props.params.family))
const person = id => data.value?.persons?.[id]

const partnerNames = computed(() => (data.value?.partners ?? []).map(id => nameLabel(person(id))))
watch(data, d => d && emit('title', t('reports.titles.family', { names: partnerNames.value.join(' ⚭ ') || '#' + d.id })))

const roleLabel = id => t('reports.partnerRole.' + (person(id)?.sex ?? 'U'))
const eventLabel = ev => (ev.event_type === 'EVEN' && ev.event_descriptor) || t('family.eventTypes.' + ev.event_type)
const childSex  = id => t('reports.childRole.' + (person(id)?.sex ?? 'U'))
</script>

<template>
  <p v-if="error" class="report-error">{{ t('reports.loadError') }}</p>
  <p v-else-if="!data" class="muted"><i class="pi pi-spin pi-spinner" /> {{ t('reports.loading') }}</p>
  <template v-else>
    <div class="report-partners">
      <section v-for="id in data.partners" :key="id" class="report-box">
        <h2>{{ roleLabel(id) }}</h2>
        <div class="report-name">{{ nameLabel(person(id)) }}</div>
        <FactList :person="person(id)" />
        <dl v-if="data.parents[id]" class="report-parents">
          <template v-for="(pid, i) in data.parents[id]" :key="i">
            <template v-if="pid">
              <dt>{{ t(i === 0 ? 'reports.father' : 'reports.mother') }}</dt>
              <dd>{{ nameWithSpan(person(pid)) }}</dd>
            </template>
          </template>
        </dl>
      </section>
    </div>

    <section v-if="data.events.length" class="report-section">
      <h2>{{ t('reports.familyEvents') }}</h2>
      <table class="report-table">
        <tbody>
          <tr v-for="(ev, i) in data.events" :key="i">
            <th>{{ eventLabel(ev) }}</th>
            <td>{{ datePlace(ev.event_date, ev.place) }}<template v-if="ev.event_value"> – {{ ev.event_value }}</template></td>
          </tr>
        </tbody>
      </table>
    </section>

    <section class="report-section">
      <h2>{{ t('reports.children') }} <small>({{ data.children.length }})</small></h2>
      <p v-if="!data.children.length" class="muted">{{ t('reports.noChildren') }}</p>
      <table v-else class="report-table report-children">
        <thead>
          <tr><th>#</th><th>{{ t('reports.cols.name') }}</th><th>{{ t('reports.cols.data') }}</th><th>{{ t('reports.cols.spouses') }}</th></tr>
        </thead>
        <tbody>
          <tr v-for="(c, i) in data.children" :key="c.person_id">
            <td>{{ i + 1 }}</td>
            <td>
              <strong>{{ nameLabel(person(c.person_id)) }}</strong>
              <div class="muted">{{ childSex(c.person_id) }}<template v-if="c.pedigree && c.pedigree !== 'BIRTH'"> · {{ t('reports.pedigree.' + c.pedigree) }}</template></div>
            </td>
            <td><FactList :person="person(c.person_id)" /></td>
            <td>
              <div v-for="(s, j) in data.spouses[c.person_id] ?? []" :key="j">
                ⚭ <template v-if="s.marriage && datePlace(s.marriage.date, s.marriage.place)">{{ datePlace(s.marriage.date, s.marriage.place) }}, </template>
                {{ s.spouse_id ? nameWithSpan(person(s.spouse_id)) : t('reports.partnerUnknown') }}
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </section>
    <SymbolLegend />
  </template>
</template>
