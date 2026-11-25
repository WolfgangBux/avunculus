<script setup>
// Eine Quellenangabe zum Lesen: Quelle, Seite, Qualität, Link, Abschrift, Original (Scan)
import MediaStrip from './MediaStrip.vue'
import MediaCropView from './MediaCropView.vue'
import { computed } from 'vue'
import { t } from '../i18n/index.js'
import { qualityStars } from '../format.js'

const props = defineProps({ citation: { type: Object, required: true } })
// Originale mit markiertem Bereich groß (lesbar), die übrigen als Vorschaubilder
const cropped = computed(() => (props.citation.media ?? []).filter(m => m.crop))
const whole   = computed(() => (props.citation.media ?? []).filter(m => !m.crop))
</script>

<template>
  <div class="view-cit">
    <div class="view-cit-head"><i class="pi pi-book" /> <strong>{{ citation.source_label || t('person.citations.sourceNone') }}</strong>
      <span v-if="citation.page" class="muted"> · {{ citation.page }}</span>
      <span v-if="qualityStars(citation.quality)" class="quality-stars" :title="t('person.citations.quality.' + citation.quality)">{{ qualityStars(citation.quality) }}</span></div>
    <a v-if="citation.url" :href="citation.url" target="_blank" rel="noopener" class="view-url" @click.stop>{{ citation.url }}</a>
    <blockquote v-if="citation.transcription" class="view-transcription">
      <span class="view-transcription-label">{{ t('person.citations.transcriptionLabel') }}</span>{{ citation.transcription }}</blockquote>
    <div v-if="cropped.length" class="view-cit-crops" @click.stop>
      <MediaCropView v-for="m in cropped" :key="m.id" :media="m" />
    </div>
    <MediaStrip v-if="whole.length" :model-value="whole" readonly class="view-cit-media" @click.stop />
  </div>
</template>
