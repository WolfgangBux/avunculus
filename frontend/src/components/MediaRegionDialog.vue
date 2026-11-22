<script setup>
import { ref, watch } from 'vue'
import Dialog from 'openvue/dialog'
import MediaRegions from './MediaRegions.vue'
import { fetchMedia } from '../api/media.js'
import { t } from '../i18n/index.js'

/** Eine Person auf einem Bild markieren (aus dem Personenformular); meldet das Medium nach dem Speichern (saved) */
const visible = defineModel('visible', { type: Boolean, default: false })
const props = defineProps({
  mediaId:  { type: Number, default: null },
  personId: { type: Number, default: null },
})
const emit = defineEmits(['saved'])

const media = ref(null)
const error = ref(false)
watch(() => [visible.value, props.mediaId], async ([v, id]) => {
  if (!v || !id) return
  media.value = null
  error.value = false
  try   { media.value = await fetchMedia(id) }
  catch { error.value = true }
}, { immediate: true })

function onSaved(m) {
  emit('saved', m)
  visible.value = false
}
</script>

<template>
  <Dialog v-model:visible="visible" modal :draggable="false" :header="t('media.region.title')"
    :style="{ width: '56rem' }" :breakpoints="{ '900px': '95vw' }">
    <div v-if="error" class="alert alert-error">{{ t('media.region.loadError') }}</div>
    <p v-else-if="!media" class="muted">{{ t('common.loading') }}</p>
    <p v-else-if="!media.links?.some(l => l.kind === 'person' && l.target_id === personId)" class="alert alert-warning">
      {{ t('media.region.notLinked') }}
    </p>
    <MediaRegions v-else :media="media" :only-person="personId" @saved="onSaved" />
  </Dialog>
</template>
