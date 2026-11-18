<script setup>
import { ref, computed, watch } from 'vue'
import { fetchStatistics } from '../api/statistics.js'
import { currentTreeId } from '../trees.js'
import { t, locale } from '../i18n/index.js'
import RankList from './RankList.vue'
import ColumnChart from './ColumnChart.vue'
import PlaceMap from './PlaceMap.vue'

/** Statistik des aktuellen Stammbaums – alles aus einem Aufruf (GET /statistics) */
const emit = defineEmits(['open-person', 'open-place'])

const stats   = ref(null)
const loading = ref(false)
const error   = ref('')

async function load() {
  if (!currentTreeId.value) { stats.value = null; return }
  loading.value = true
  error.value   = ''
  try   { stats.value = await fetchStatistics() }
  catch (e) { error.value = e?.error || t('stats.loadError') }
  finally { loading.value = false }
}
watch(currentTreeId, load, { immediate: true })

const fmt = n => (n ?? 0).toLocaleString(locale.value)
const pct = (n, total) => total ? Math.round(1000 * n / total) / 10 : 0

const tiles = computed(() => {
  const c = stats.value?.counts ?? {}
  return ['persons', 'families', 'events', 'sources', 'citations', 'places', 'media', 'surnames']
    .map(k => ({ key: k, value: c[k] ?? 0 }))
})
const range = computed(() => {
  const r = stats.value?.range
  return r?.first ? `${r.first}–${r.last}` : '–'
})

const SEX_ORDER = ['M', 'F', 'X', 'U']
const sexRows = computed(() => {
  const s = stats.value?.sex ?? {}
  const total = SEX_ORDER.reduce((a, k) => a + (s[k] ?? 0), 0)
  return SEX_ORDER.filter(k => s[k]).map(k => ({ key: k, count: s[k], share: pct(s[k], total) }))
})

const births = computed(() => (stats.value?.births ?? []).map(b => ({ x: b.decade, y: b.count, label: `${b.decade}–${b.decade + 9}` })))

const eventRows = computed(() => (stats.value?.event_types ?? []).map(e => ({
  label: t((e.scope === 'family' ? 'family.eventTypes.' : 'person.eventTypes.') + e.type),
  title: e.type, count: e.count,
})))

const quality = computed(() => {
  const q = stats.value?.quality
  if (!q) return []
  return ['with_birth', 'with_death', 'with_parents', 'with_source', 'with_sex']
    .map(k => ({ key: k, count: q[k], share: pct(q[k], q.total) }))
})
const qualityNotes = computed(() => {
  const q = stats.value?.quality ?? {}
  return ['isolated', 'invalid_dates', 'implausible'].map(k => ({ key: k, count: q[k] ?? 0 }))
})

const maxLife = computed(() => Math.max(1, ...(stats.value?.lifespan ?? []).map(l => Math.max(l.avg_age, l.avg_adult ?? 0))))
const maxMarr = computed(() => Math.max(1, ...(stats.value?.marriage_age ?? []).map(m => Math.max(m.M ?? 0, m.F ?? 0))))
const century = c => t('stats.century', { n: c / 100 + 1, from: c, to: c + 99 })
</script>

<template>
  <div class="stats-page">
    <div class="stats-head">
      <h1 class="stats-title">{{ t('stats.title') }}</h1>
      <span v-if="stats" class="muted">{{ t('stats.range') }}: <strong>{{ range }}</strong></span>
    </div>

    <p v-if="loading && !stats" class="card stats-loading"><i class="pi pi-spin pi-spinner" /> {{ t('stats.loading') }}</p>
    <p v-else-if="error" class="card alert">{{ error }}</p>

    <template v-if="stats">
      <!-- Umfang -->
      <div class="stats-tiles">
        <div v-for="tile in tiles" :key="tile.key" class="stat-tile">
          <div class="stat-tile-value">{{ fmt(tile.value) }}</div>
          <div class="stat-tile-label">{{ t('stats.counts.' + tile.key) }}</div>
        </div>
      </div>

      <div class="stats-grid">
        <!-- Geburten je Jahrzehnt -->
        <section class="card stats-wide">
          <h2 class="card-title">{{ t('stats.births') }}</h2>
          <p class="stats-sub">{{ t('stats.birthsHint') }}</p>
          <ColumnChart :data="births" :label-every="50" :value-label="t('stats.persons')" />
        </section>

        <!-- Ortskarte -->
        <section class="card stats-wide">
          <h2 class="card-title">{{ t('stats.map.title') }}</h2>
          <p class="stats-sub">{{ t('stats.map.hint') }}</p>
          <PlaceMap @open-place="id => emit('open-place', id)" />
        </section>

        <!-- Geschlecht -->
        <section class="card">
          <h2 class="card-title">{{ t('stats.sex') }}</h2>
          <ul class="sex-bars">
            <li v-for="s in sexRows" :key="s.key">
              <span class="sex-bars-label">{{ t('sex.' + s.key) }}</span>
              <span class="sex-bars-track"><span :class="['sex-bars-fill', 'sex-' + s.key]" :style="{ width: s.share + '%' }" /></span>
              <span class="sex-bars-value">{{ fmt(s.count) }} <span class="muted">({{ s.share }} %)</span></span>
            </li>
          </ul>
        </section>

        <!-- Datenqualität -->
        <section class="card">
          <h2 class="card-title">{{ t('stats.quality') }}</h2>
          <ul class="quality-bars">
            <li v-for="q in quality" :key="q.key">
              <div class="quality-row">
                <span>{{ t('stats.qualityItems.' + q.key) }}</span>
                <span><strong>{{ q.share }} %</strong> <span class="muted">· {{ fmt(q.count) }}</span></span>
              </div>
              <span class="quality-track"><span class="quality-fill" :style="{ width: q.share + '%' }" /></span>
            </li>
          </ul>
          <ul class="quality-notes">
            <li v-for="n in qualityNotes" :key="n.key">
              <i :class="['pi', n.count ? 'pi-exclamation-circle' : 'pi-check-circle']" :data-ok="!n.count" />
              {{ t('stats.qualityNotes.' + n.key, { n: fmt(n.count) }) }}
            </li>
          </ul>
        </section>

        <!-- Sterbealter -->
        <section class="card">
          <h2 class="card-title">{{ t('stats.lifespan') }}</h2>
          <p class="stats-sub">{{ t('stats.lifespanHint') }}</p>
          <div class="stats-table-wrap">
            <table class="stats-table">
              <thead><tr>
                <th>{{ t('stats.born') }}</th><th class="num">{{ t('stats.deceased') }}</th>
                <th>{{ t('stats.avgAge') }}</th><th>{{ t('stats.avgAdult') }}</th><th class="num">{{ t('stats.childShare') }}</th>
              </tr></thead>
              <tbody>
                <tr v-for="l in stats.lifespan" :key="l.century">
                  <td :title="century(l.century)">{{ l.century }}–{{ l.century + 99 }}</td>
                  <td class="num">{{ fmt(l.count) }}</td>
                  <td><span class="inline-bar"><span :style="{ width: (100 * l.avg_age / maxLife) + '%' }" /></span> {{ l.avg_age }}</td>
                  <td><span class="inline-bar"><span :style="{ width: (100 * (l.avg_adult ?? 0) / maxLife) + '%' }" /></span> {{ l.avg_adult ?? '–' }}</td>
                  <td class="num">{{ l.child_share }} %</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <!-- Heiratsalter -->
        <section class="card">
          <h2 class="card-title">{{ t('stats.marriageAge') }}</h2>
          <p class="stats-sub">{{ t('stats.marriageAgeHint') }}</p>
          <div class="stats-table-wrap">
            <table class="stats-table">
              <thead><tr><th>{{ t('stats.born') }}</th><th>{{ t('sex.M') }}</th><th>{{ t('sex.F') }}</th></tr></thead>
              <tbody>
                <tr v-for="m in stats.marriage_age" :key="m.century">
                  <td>{{ m.century }}–{{ m.century + 99 }}</td>
                  <td :title="t('stats.basedOn', { n: fmt(m.count_M) })">
                    <span class="inline-bar sex-M"><span :style="{ width: (100 * (m.M ?? 0) / maxMarr) + '%' }" /></span> {{ m.M ?? '–' }}
                  </td>
                  <td :title="t('stats.basedOn', { n: fmt(m.count_F) })">
                    <span class="inline-bar sex-F"><span :style="{ width: (100 * (m.F ?? 0) / maxMarr) + '%' }" /></span> {{ m.F ?? '–' }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <!-- Älteste Personen -->
        <section class="card">
          <h2 class="card-title">{{ t('stats.oldest') }}</h2>
          <ol class="oldest-list">
            <li v-for="o in stats.oldest" :key="o.person_id">
              <button type="button" :class="['rel-chip', 'rel-chip-sm', 'sex-' + o.sex]" @click="emit('open-person', o.person_id)">
                <span class="rel-name">{{ o.name }}</span>
                <span class="rel-years">{{ o.birth_year }}–{{ o.death_year }}</span>
              </button>
              <strong class="oldest-age">{{ t('stats.years', { n: o.age }) }}</strong>
            </li>
          </ol>
        </section>

        <!-- Ranglisten -->
        <section class="card">
          <h2 class="card-title">{{ t('stats.surnames') }}</h2>
          <RankList :items="stats.surnames" />
        </section>
        <section class="card">
          <h2 class="card-title">{{ t('stats.givenNamesM') }}</h2>
          <RankList :items="stats.given_names.M" />
        </section>
        <section class="card">
          <h2 class="card-title">{{ t('stats.givenNamesF') }}</h2>
          <RankList :items="stats.given_names.F" />
        </section>
        <section class="card">
          <h2 class="card-title">{{ t('stats.birthPlaces') }}</h2>
          <RankList :items="stats.birth_places" />
        </section>
        <section class="card">
          <h2 class="card-title">{{ t('stats.deathPlaces') }}</h2>
          <RankList :items="stats.death_places" />
        </section>
        <section class="card">
          <h2 class="card-title">{{ t('stats.occupations') }}</h2>
          <RankList :items="stats.occupations" />
        </section>
        <section class="card stats-wide">
          <h2 class="card-title">{{ t('stats.eventTypes') }}</h2>
          <RankList :items="eventRows" columns />
        </section>
      </div>
    </template>
  </div>
</template>
