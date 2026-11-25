<script setup>
import { ref, computed } from 'vue'
import { mediaFileUrl } from '../api/media.js'
import { t } from '../i18n/index.js'

/**
 * Markierter Bereich eines Bilds in voller Auflösung (z. B. die zitierte Stelle im Kirchenbuch-Scan),
 * per CSS aus dem Originalbild geschnitten – anders als das Vorschaubild bleibt Schrift lesbar.
 * Ein Klick öffnet das ganze Bild.
 */
const props = defineProps({
  media: { type: Object, required: true },   // { id, title, width, height, crop: { left, top, width, height } }
})

// Seitenverhältnis des Bilds; die gespeicherte Größe kann ungedreht sein, nach dem Laden zählt das angezeigte Bild
const natural = ref(null)
const ratio = computed(() => {
  const [w, h] = natural.value ?? [props.media.width, props.media.height]
  return w && h ? w / h : 4 / 3
})
const c = computed(() => props.media.crop)
const aspect = computed(() => (c.value.width / c.value.height) * ratio.value)
const frame = computed(() => ({
  aspectRatio: aspect.value,
  // breite Ausschnitte bis 40rem, hohe höchstens 20rem hoch
  width: `min(100%, 40rem, ${(20 * aspect.value).toFixed(2)}rem)`,
}))
const image = computed(() => ({
  width: 100 / c.value.width + '%',
  left:  (-c.value.left / c.value.width) * 100 + '%',
  top:   (-c.value.top / c.value.height) * 100 + '%',
}))
</script>

<template>
  <a :href="mediaFileUrl(media.id)" target="_blank" rel="noopener" class="media-crop" :style="frame"
    :title="(media.title ? media.title + ' – ' : '') + t('media.citationRegion.open')">
    <img :src="mediaFileUrl(media.id)" :alt="media.title || ''" :style="image" loading="lazy"
      @load="e => (natural = [e.target.naturalWidth, e.target.naturalHeight])" />
  </a>
</template>
