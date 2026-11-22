<script setup>
import { ref, watch } from 'vue'
import { mediaThumbUrl, mediaIcon } from '../api/media.js'

/**
 * Vorschaubild eines Mediums; ohne Vorschau (PDF, Video …) ein Symbol.
 * Bringt das Medium einen Ausschnitt mit (crop – Medien einer Person), zeigt es nur diesen.
 */
const props = defineProps({
  media: { type: Object, required: true },   // { id, mime_type, title, crop? }
  size:  { type: String, default: 'md' },    // xs | sm | md | lg
})
const failed = ref(false)
watch(() => props.media.id, () => { failed.value = false })
const canThumb = m => /^image\/(jpeg|png|gif|webp|bmp)$/.test(m.mime_type ?? '')
</script>

<template>
  <span :class="['media-thumb', 'media-thumb-' + size]">
    <img v-if="canThumb(media) && !failed" :src="mediaThumbUrl(media.id, media.crop)" :alt="media.title || ''" loading="lazy"
      @error="failed = true" />
    <i v-else :class="['pi', mediaIcon(media)]" aria-hidden="true" />
  </span>
</template>
