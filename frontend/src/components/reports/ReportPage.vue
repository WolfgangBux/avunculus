<script setup>
import { ref, computed, onMounted } from 'vue'
import Button from 'openvue/button'
import { fetchTrees } from '../../api/trees.js'
import { currentTreeId } from '../../trees.js'
import { t, setLocale } from '../../i18n/index.js'
import { parseReportHash } from '../../composables/reports.js'
import { APP_VERSION } from '../../about.js'
import AncestorReport   from './AncestorReport.vue'
import DescendantReport from './DescendantReport.vue'
import FamilySheet      from './FamilySheet.vue'
import PersonSheet      from './PersonSheet.vue'
import EventList        from './EventList.vue'
import './report.css'

/**
 * Druckansicht eines Berichts (eigener Tab, Adresse #/report/<Art>?tree=…). Ohne App-Rahmen:
 * oben eine Leiste zum Drucken (im Ausdruck ausgeblendet), darunter das Blatt.
 * Als PDF speichert man über den Druckdialog des Browsers.
 */
const report = parseReportHash()
const p      = report?.params ?? {}
if (p.lang) setLocale(p.lang)
// Baum nur für diesen Tab – nicht als „zuletzt geöffnet“ merken (sonst wechselt die App im anderen Tab)
currentTreeId.value = Number(p.tree) || null

const COMPONENTS = { ancestors: AncestorReport, descendants: DescendantReport, family: FamilySheet, person: PersonSheet }
const component  = computed(() => COMPONENTS[report?.kind] ?? EventList)

const treeName = ref('')
const title    = ref('')     // vom Bericht gemeldet (z. B. „Ahnentafel von Maria Huber“)
const ready    = ref(false)
const created  = new Date().toLocaleDateString(p.lang === 'en' ? 'en-GB' : 'de-DE')

function onTitle(text) {
  title.value = text
  document.title = [text, treeName.value].filter(Boolean).join(' – ')
}

onMounted(async () => {
  try {
    treeName.value = (await fetchTrees()).find(tr => tr.id === currentTreeId.value)?.name ?? ''
  } catch { /* Name ist nur Zierde */ }
  ready.value = true
})

const print = () => window.print()
const close = () => window.close()
</script>

<template>
  <div class="report-toolbar no-print">
    <span class="report-toolbar-title">{{ t('reports.preview') }}</span>
    <span class="report-toolbar-hint">{{ t('reports.pdfHint') }}</span>
    <Button :label="t('reports.print')" icon="pi pi-print" size="small" @click="print" />
    <Button :label="t('action.close')" icon="pi pi-times" size="small" severity="secondary" text @click="close" />
  </div>

  <main class="report-sheet">
    <p v-if="!report" class="report-error">{{ t('reports.unknown') }}</p>
    <template v-else-if="ready">
      <header class="report-header">
        <h1>{{ title || t('reports.kinds.' + report.kind) }}</h1>
        <p class="report-meta">
          <span v-if="treeName">{{ t('reports.tree', { name: treeName }) }}</span>
          <span>{{ t('reports.created', { date: created }) }}</span>
        </p>
      </header>
      <component :is="component" :kind="report.kind" :params="p" @title="onTitle" />
      <footer class="report-footer">Avunculus {{ APP_VERSION }}</footer>
    </template>
  </main>
</template>
