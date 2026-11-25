<script setup>
import { ref, watch } from 'vue'
import Dialog from 'openvue/dialog'
import Button from 'openvue/button'
import { mediaFileUrl } from '../api/media.js'
import { useRegionDrag, regionBox } from '../composables/regionDrag.js'
import { t } from '../i18n/index.js'

/**
 * Auf dem Original einer Quellenangabe den Bereich markieren, den die Transkription wiedergibt.
 * Gespeichert wird nicht hier, sondern mit der Quellenangabe: apply meldet den Rahmen (null = entfernt).
 */
const visible = defineModel('visible', { type: Boolean, default: false })
const props = defineProps({
  media: { type: Object, default: null },   // { id, title, crop? }
})
const emit = defineEmits(['apply'])

const img   = ref(null)
const draft = ref(null)
watch(() => [visible.value, props.media], ([v]) => {
  if (v) draft.value = props.media?.crop ? { ...props.media.crop, shape: 'rect' } : null
}, { immediate: true })

const { down, move, up } = useRegionDrag(img, draft)

function apply(crop) {
  emit('apply', crop ? { left: crop.left, top: crop.top, width: crop.width, height: crop.height } : null)
  visible.value = false
}
</script>

<template>
  <Dialog v-model:visible="visible" modal :draggable="false" :header="t('media.citationRegion.title')"
    :style="{ width: '64rem' }" :breakpoints="{ '1100px': '95vw' }">
    <div v-if="media" class="media-regions">
      <div class="region-stage editing" @pointerdown="down" @pointermove="move" @pointerup="up" @pointercancel="up">
        <img ref="img" :src="mediaFileUrl(media.id)" :alt="media.title || ''" draggable="false" />
        <div v-if="draft" class="region region-edit" :style="regionBox(draft)" data-frame="1">
          <span v-for="h in ['nw', 'ne', 'sw', 'se']" :key="h" :class="['region-handle', 'region-handle-' + h]" :data-handle="h" />
        </div>
      </div>
      <div class="region-toolbar">
        <p class="region-hint">{{ draft ? t('media.citationRegion.hintAdjust') : t('media.citationRegion.hintDraw') }}</p>
        <div class="region-actions">
          <span class="spacer" />
          <Button v-if="media.crop" :label="t('media.citationRegion.remove')" icon="pi pi-trash" severity="danger" text size="small"
            @click="apply(null)" />
          <Button :label="t('action.cancel')" text size="small" @click="visible = false" />
          <Button :label="t('media.citationRegion.apply')" icon="pi pi-check" size="small" :disabled="!draft" @click="apply(draft)" />
        </div>
      </div>
    </div>
  </Dialog>
</template>
