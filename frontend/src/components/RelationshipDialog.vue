<script setup>
import { ref, computed, watch } from 'vue'
import Dialog from 'openvue/dialog'
import Button from 'openvue/button'
import PersonPicker from './PersonPicker.vue'
import { fetchRelationship } from '../api/relationship.js'
import { showRelationship, relationshipStart } from '../composables/relationship.js'
import { currentTreeId } from '../trees.js'
import { nameLabel, lifeSpan, sexClass } from '../format.js'
import { relationTerm, reverseRelation, stepTerm } from '../kinship.js'
import { t } from '../i18n/index.js'

/**
 * Verwandtschaftsrechner: zwei Personen wählen, die Verwandtschaft wird benannt (Onkel 2. Grades, Schwager …)
 * und der Weg über die gemeinsamen Vorfahren gezeigt. Klick auf eine Person öffnet sie.
 */
const visible = showRelationship

const a       = ref('')
const b       = ref('')
const aLabel  = ref('')
const bLabel  = ref('')
const result  = ref(null)
const loading = ref(false)
const error   = ref(false)
let   seq     = 0

watch(visible, v => {
  if (!v) return
  const start = relationshipStart.value
  a.value = start.a ?? ''
  b.value = start.b ?? ''
  aLabel.value = bLabel.value = ''
  result.value = null
})

watch([a, b], load)
watch(currentTreeId, () => { a.value = b.value = ''; result.value = null })

async function load() {
  const my = ++seq
  error.value = false
  if (!a.value || !b.value || !visible.value) {
    // Beim Klick auf einen Vorschlag meldet die Auswahlliste kurz „leer“ (force-selection). Sofort ausgeblendet
    // würde der Dialog schrumpfen und der Klick daneben landen – daher erst nach einem Moment.
    setTimeout(() => { if (my === seq) result.value = null }, 300)
    return
  }
  loading.value = true
  try {
    const res = await fetchRelationship(a.value, b.value)
    if (my !== seq) return
    result.value = res
    // vorbelegte Personen kennen erst jetzt ihren Namen
    aLabel.value = label(res.a)
    bLabel.value = label(res.b)
  } catch {
    if (my === seq) { result.value = null; error.value = true }
  } finally {
    if (my === seq) loading.value = false
  }
}

function swap() {
  ;[a.value, b.value] = [b.value, a.value]
  ;[aLabel.value, bLabel.value] = [bLabel.value, aLabel.value]
}

const person = id => result.value?.persons?.[id]
const sex    = id => person(id)?.sex ?? 'U'
const name   = id => (person(id) ? nameLabel(person(id)) : '#' + id)
function label(id) {
  const p = person(id)
  if (!p) return '#' + id
  const span = lifeSpan(p)
  return span ? `${nameLabel(p)} (${span})` : nameLabel(p)
}

const relations = computed(() => result.value?.relations ?? [])
const multiple  = computed(() => relations.value.filter(r => r.type === 'blood').length > 1)

/** Anzeige je Verwandtschaft: Bezeichnung aus Sicht von A und B, Zusatzzeilen */
const cards = computed(() => {
  const r0 = result.value
  if (!r0) return []
  return relations.value.map(rel => {
    const termA = relationTerm(rel, sex(r0.a))
    const termB = relationTerm(reverseRelation(rel), sex(r0.b))
    const lines = []
    if (rel.type === 'blood') {
      lines.push({ text: t(rel.ga && rel.gb ? 'kinship.degreeCollateral' : 'kinship.degreeDirect', { n: rel.ga + rel.gb }) })
    }
    if (rel.type === 'inlaw') lines.push({ text: t('kinship.via'), ids: [rel.via] })
    if (rel.type === 'path')  lines.push({ text: t('kinship.notRelated', { n: rel.path.length - 2 }) })
    return { rel, termA, termB, lines, sentences: rel.type !== 'same' && rel.type !== 'path',
             chart: rel.type === 'blood' || rel.type === 'inlaw' ? chartOf(rel) : null }
  })
})

const INVERSE = { parent: 'child', child: 'parent', partner: 'partner' }

/**
 * Verwandtschaftsdiagramm: oben der höchste Punkt des Wegs (gemeinsamer Vorfahr), darunter links die Linie zu A,
 * rechts die zu B. Jede Karte sagt, was die Person für die Karte darüber ist (Sohn, Tochter, Ehefrau …).
 */
function chartOf(rel) {
  const path = rel.path
  let level = 0, apex = 0, top = 0
  path.forEach((s, i) => {
    level += s.rel === 'parent' ? -1 : s.rel === 'child' ? 1 : 0
    if (level < top) { top = level; apex = i }
  })
  const left = []
  for (let i = apex - 1; i >= 0; i--) left.push({ id: path[i].id, rel: INVERSE[path[i + 1].rel] })
  const right = path.slice(apex + 1).map(s => ({ id: s.id, rel: s.rel }))
  const apexId = path[apex].id
  const ancestors = rel.ancestors?.includes(apexId) ? [apexId, ...rel.ancestors.filter(id => id !== apexId)] : [apexId]
  return { ancestors, isCommon: apex > 0 && apex < path.length - 1 && rel.ancestors?.includes(apexId), columns: [left, right].filter(c => c.length) }
}

function go(id) {
  location.hash = `#/${currentTreeId.value}/persons/${id}`
  visible.value = false
}
</script>

<template>
  <Dialog v-model:visible="visible" modal :draggable="false" :header="t('kinship.title')"
    :style="{ width: '48rem' }" :breakpoints="{ '780px': '95vw' }">

    <p class="muted dialog-intro">{{ t('kinship.intro') }}</p>

    <div class="kin-pickers">
      <div class="kin-picker form-group">
        <label>{{ t('kinship.personA') }}</label>
        <PersonPicker v-model="a" v-model:label="aLabel" :placeholder="t('kinship.choose')" />
      </div>
      <Button icon="pi pi-arrow-right-arrow-left" text rounded class="kin-swap" :disabled="!a && !b"
        :aria-label="t('kinship.swap')" v-tooltip.top="t('kinship.swap')" @click="swap" />
      <div class="kin-picker form-group">
        <label>{{ t('kinship.personB') }}</label>
        <PersonPicker v-model="b" v-model:label="bLabel" :placeholder="t('kinship.choose')" />
      </div>
    </div>

    <p v-if="loading && !result" class="muted"><i class="pi pi-spin pi-spinner" /> {{ t('kinship.running') }}</p>
    <div v-else-if="error" class="alert alert-error">{{ t('kinship.loadError') }}</div>
    <p v-else-if="result && !relations.length" class="kin-none"><i class="pi pi-info-circle" /> {{ t('kinship.none') }}</p>

    <div v-else-if="result" :class="['kin-results', { stale: loading }]">
      <p v-if="multiple" class="muted kin-multiple">{{ t('kinship.multiple') }}</p>

      <section v-for="(c, n) in cards" :key="n" class="kin-card">
        <div class="kin-term">{{ c.termA }}</div>
        <template v-if="c.sentences">
          <p class="kin-sentence">
            <a href="#" @click.prevent="go(result.a)">{{ name(result.a) }}</a>
            {{ t('kinship.is') }} <strong>{{ c.termA }}</strong> {{ t('kinship.of') }}
            <a href="#" @click.prevent="go(result.b)">{{ name(result.b) }}</a>
          </p>
          <p class="kin-sentence">
            <a href="#" @click.prevent="go(result.b)">{{ name(result.b) }}</a>
            {{ t('kinship.is') }} <strong>{{ c.termB }}</strong> {{ t('kinship.of') }}
            <a href="#" @click.prevent="go(result.a)">{{ name(result.a) }}</a>
          </p>
        </template>
        <p v-for="(l, i) in c.lines" :key="i" class="kin-line muted">
          {{ l.text }}<template v-if="l.ids">
            <template v-for="(id, j) in l.ids" :key="id">{{ j ? ` ${t('kinship.and')} ` : ' ' }}<a href="#" @click.prevent="go(id)">{{ label(id) }}</a></template>
          </template>
        </p>

        <div v-if="c.chart" class="kin-chart" :aria-label="t('kinship.path')">
          <div class="kin-apex">
            <span v-if="c.chart.isCommon" class="kin-step-term">{{ t(c.chart.ancestors.length > 1 ? 'kinship.commonAncestors' : 'kinship.commonAncestor') }}</span>
            <div class="kin-apex-persons">
              <template v-for="(id, j) in c.chart.ancestors" :key="id">
                <i v-if="j" class="pi pi-heart kin-arrow" aria-hidden="true" />
                <button type="button" :class="['kin-person', sexClass(person(id)), { end: id === result.a || id === result.b }]" @click="go(id)">
                  <span class="kin-step-name">{{ name(id) }}</span>
                  <span class="kin-step-years">{{ person(id) ? lifeSpan(person(id)) : '' }}</span>
                </button>
              </template>
            </div>
          </div>
          <div class="kin-columns">
            <ol v-for="(col, k) in c.chart.columns" :key="k" class="kin-col">
              <li v-for="s in col" :key="s.id" :class="['kin-col-step', { partner: s.rel === 'partner' }]">
                <span class="kin-link" aria-hidden="true"><i v-if="s.rel === 'partner'" class="pi pi-heart" /></span>
                <button type="button" :class="['kin-person', sexClass(person(s.id)), { end: s.id === result.a || s.id === result.b }]" @click="go(s.id)">
                  <span class="kin-step-term">{{ stepTerm(s, sex(s.id)) }}</span>
                  <span class="kin-step-name">{{ name(s.id) }}</span>
                  <span class="kin-step-years">{{ person(s.id) ? lifeSpan(person(s.id)) : '' }}</span>
                </button>
              </li>
            </ol>
          </div>
        </div>

        <ol v-else-if="c.rel.path.length > 1" class="kin-path" :aria-label="t('kinship.path')">
          <li v-for="(s, i) in c.rel.path" :key="i" class="kin-step">
            <i v-if="i" :class="['pi', s.rel === 'parent' ? 'pi-arrow-up' : s.rel === 'child' ? 'pi-arrow-down' : 'pi-heart', 'kin-arrow']" aria-hidden="true" />
            <button type="button" :class="['kin-person', sexClass(person(s.id)), { end: !i || i === c.rel.path.length - 1 }]" @click="go(s.id)">
              <span class="kin-step-term">{{ i ? stepTerm(s, sex(s.id)) : ' ' }}</span>
              <span class="kin-step-name">{{ name(s.id) }}</span>
              <span class="kin-step-years">{{ person(s.id) ? lifeSpan(person(s.id)) : '' }}</span>
            </button>
          </li>
        </ol>
      </section>
    </div>

    <template #footer>
      <Button :label="t('action.close')" severity="secondary" outlined @click="visible = false" />
    </template>
  </Dialog>
</template>

<style scoped>
.kin-pickers { display: flex; align-items: flex-end; gap: .5rem; margin-bottom: 1rem; }
.kin-picker { flex: 1; min-width: 0; }
.kin-swap { flex-shrink: 0; margin-bottom: .15rem; }
@media (max-width: 560px) {
  .kin-pickers { flex-direction: column; align-items: stretch; }
  .kin-swap { align-self: center; transform: rotate(90deg); }
}
.kin-none { display: flex; align-items: center; gap: .5rem; }
.kin-results { display: flex; flex-direction: column; gap: .7rem; max-height: 62vh; overflow-y: auto; }
.kin-results.stale { opacity: .55; }
.kin-multiple { font-size: .82rem; margin: 0; }
.kin-card { border: 1px solid var(--color-border); border-radius: var(--radius-sm); background: var(--color-surface); padding: .7rem .85rem; }
.kin-term { font-size: 1.15rem; font-weight: 600; color: var(--color-primary); margin-bottom: .3rem; }
.kin-term::first-letter { text-transform: uppercase; }
.kin-sentence { margin: .1rem 0; font-size: .88rem; }
.kin-line { margin: .3rem 0 0; font-size: .8rem; }
.kin-sentence a, .kin-line a { color: inherit; text-decoration: underline; text-decoration-color: var(--color-border); text-underline-offset: 2px; }
.kin-sentence a:hover, .kin-line a:hover { text-decoration-color: var(--color-primary); }

.kin-path { list-style: none; padding: 0; margin: .7rem 0 0; display: flex; flex-wrap: wrap; align-items: center; gap: .35rem; }
.kin-step { display: flex; align-items: center; gap: .35rem; min-width: 0; }
.kin-arrow { font-size: .7rem; color: var(--color-muted); }
.kin-person { display: flex; flex-direction: column; align-items: flex-start; min-width: 0; max-width: 13rem; padding: .25rem .5rem;
  border: 1px solid var(--color-border); border-left: 3px solid var(--sex-fg, var(--sex-u-fg)); border-radius: var(--radius-sm);
  background: var(--color-bg, transparent); color: inherit; font: inherit; text-align: left; cursor: pointer; }
.kin-person:hover { border-color: var(--color-primary); border-left-color: var(--sex-fg, var(--sex-u-fg)); background: var(--color-primary-soft); }
.kin-person.end { font-weight: 600; background: var(--color-primary-soft); }

/* Diagramm: Vorfahr oben, die beiden Linien darunter, verbunden durch senkrechte Striche */
.kin-chart { margin-top: .8rem; display: flex; flex-direction: column; align-items: center; }
.kin-apex { display: flex; flex-direction: column; align-items: center; gap: .15rem; }
.kin-apex-persons { display: flex; align-items: center; gap: .35rem; flex-wrap: wrap; justify-content: center; }
.kin-columns { display: flex; justify-content: center; gap: 1.5rem; width: 100%; position: relative; }
.kin-col { list-style: none; padding: 0; margin: 0; flex: 0 1 14rem; min-width: 0; display: flex; flex-direction: column; align-items: stretch; }
.kin-col-step { display: flex; flex-direction: column; align-items: center; }
.kin-col-step .kin-person { width: 100%; max-width: none; }
.kin-link { display: block; width: 1px; height: .9rem; background: var(--color-border); }
.kin-col-step.partner .kin-link { width: auto; height: 1.2rem; background: none; color: var(--color-muted); font-size: .7rem; line-height: 1.2rem; }
/* zwei Linien: Querstrich vom Vorfahren zu beiden Spalten */
.kin-columns:has(.kin-col + .kin-col) { padding-top: .9rem; }
.kin-columns:has(.kin-col + .kin-col)::before { content: ''; position: absolute; top: 0; left: 50%; width: 1px; height: .45rem; background: var(--color-border); }
.kin-columns:has(.kin-col + .kin-col) .kin-col { position: relative; }
.kin-columns:has(.kin-col + .kin-col) .kin-col::before { content: ''; position: absolute; top: -.45rem; height: 1px; background: var(--color-border); }
.kin-columns:has(.kin-col + .kin-col) .kin-col:first-child::before { left: 50%; right: -.75rem; }
.kin-columns:has(.kin-col + .kin-col) .kin-col:last-child::before { left: -.75rem; right: 50%; }
.kin-columns:has(.kin-col + .kin-col) .kin-col-step:first-child > .kin-link { height: .45rem; margin-top: -.45rem; }
.kin-step-term { font-size: .68rem; color: var(--color-muted); font-weight: normal; }
.kin-step-term::first-letter { text-transform: uppercase; }
.kin-step-name { font-size: .82rem; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.kin-step-years { font-size: .7rem; color: var(--color-muted); font-weight: normal; font-variant-numeric: tabular-nums; }
</style>
