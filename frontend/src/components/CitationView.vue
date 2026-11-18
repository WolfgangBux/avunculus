<script setup>
// Eine Quellenangabe zum Lesen: Quelle, Seite, Qualität, Link, Abschrift
import { t } from '../i18n/index.js'
import { qualityStars } from '../format.js'

defineProps({ citation: { type: Object, required: true } })
</script>

<template>
  <div class="view-cit">
    <div class="view-cit-head"><i class="pi pi-book" /> <strong>{{ citation.source_label || t('person.citations.sourceNone') }}</strong>
      <span v-if="citation.page" class="muted"> · {{ citation.page }}</span>
      <span v-if="qualityStars(citation.quality)" class="quality-stars" :title="t('person.citations.quality.' + citation.quality)">{{ qualityStars(citation.quality) }}</span></div>
    <a v-if="citation.url" :href="citation.url" target="_blank" rel="noopener" class="view-url" @click.stop>{{ citation.url }}</a>
    <blockquote v-if="citation.transcription" class="view-transcription">
      <span class="view-transcription-label">{{ t('person.citations.transcriptionLabel') }}</span>{{ citation.transcription }}</blockquote>
  </div>
</template>
