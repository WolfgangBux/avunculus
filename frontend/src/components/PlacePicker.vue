<script setup>
import { ref, watch } from 'vue'
import AutoComplete from 'openvue/autocomplete'
import { searchPlaces } from '../api/places.js'
import { t } from '../i18n/index.js'

/**
 * Auswahl eines Orts aus der Ortstabelle per Server-Suche.
 *   v-model:place-id    – ID des gewählten Orts (null = keiner bzw. neu)
 *   v-model:place-name  – ganze Ortsangabe („Ratzing, Waldkirchen, …“)
 * Mit allowNew darf eine neue Ortsangabe getippt werden: place-id bleibt dann null, der Server
 * ordnet sie beim Speichern einem vorhandenen Ort zu oder legt die fehlenden Ebenen an.
 */
const props = defineProps({
  placeId:     { type: [Number, String], default: null },
  placeName:   { type: String, default: '' },
  allowNew:    { type: Boolean, default: true },
  excludeIds:  { type: Array, default: () => [] },
  placeholder: { type: String, default: '' },
  size:        { type: String, default: undefined },
  inputId:     { type: String, default: undefined },
})
const emit = defineEmits(['update:placeId', 'update:placeName'])

const text        = ref(props.placeName ?? '')
const suggestions = ref([])
let   seq         = 0

watch(() => props.placeName, v => { if ((v ?? '') !== (typeof text.value === 'string' ? text.value : '')) text.value = v ?? '' })

async function search(event) {
  const my = ++seq
  try {
    const res = await searchPlaces(event.query.trim(), 15)
    if (my === seq) suggestions.value = res.filter(p => !props.excludeIds.includes(p.id))
  } catch {
    if (my === seq) suggestions.value = []
  }
}

// Tippen: Verknüpfung lösen (bei allowNew bleibt der Text als neue Ortsangabe)
function onInput(v) {
  if (typeof v !== 'string') return
  text.value = v
  emit('update:placeId', null)
  emit('update:placeName', v)
}
function onSelect(e) {
  text.value = e.value.full_name
  emit('update:placeId', e.value.id)
  emit('update:placeName', e.value.full_name)
}
// Ohne allowNew: nicht gewählte Eingabe verwerfen
function onBlur() {
  if (!props.allowNew && !props.placeId && text.value) { text.value = ''; emit('update:placeName', '') }
}
</script>

<template>
  <div class="place-picker">
    <AutoComplete
      :model-value="text"
      :suggestions="suggestions"
      option-label="full_name"
      :placeholder="placeholder"
      :input-id="inputId"
      :size="size"
      :delay="200"
      fluid
      @update:model-value="onInput"
      @complete="search"
      @option-select="onSelect"
      @blur="onBlur"
    >
      <template #option="{ option }">
        <div class="place-option">
          <i class="pi pi-map-marker" />
          <span class="place-option-name">
            <strong>{{ option.name }}</strong><span v-if="option.full_name !== option.name" class="place-option-rest">{{ option.full_name.slice(option.name.length) }}</span>
          </span>
          <span v-if="option.place_type" class="place-option-type">{{ option.place_type }}</span>
        </div>
      </template>
    </AutoComplete>
    <i v-if="placeId" class="pi pi-link place-picker-state linked" v-tooltip.top="t('place.picker.linked')" />
    <i v-else-if="allowNew && placeName?.trim()" class="pi pi-plus-circle place-picker-state new" v-tooltip.top="t('place.picker.new')" />
  </div>
</template>

<style scoped>
.place-picker { position: relative; display: flex; align-items: center; gap: .3rem; }
.place-picker > :first-child { flex: 1; min-width: 0; }
.place-picker-state { font-size: .8rem; flex-shrink: 0; }
.place-picker-state.linked { color: var(--color-primary); }
.place-picker-state.new    { color: var(--color-faint); }
.place-option { display: flex; align-items: center; gap: .5rem; width: 100%; min-width: 0; }
.place-option .pi { color: var(--color-primary); font-size: .8rem; }
.place-option-name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.place-option-rest { color: var(--color-muted); }
.place-option-type { font-size: .72rem; color: var(--color-faint); }
</style>
