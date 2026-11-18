<script setup>
import { ref, watch } from 'vue'
import Dialog from 'openvue/dialog'
import Button from 'openvue/button'
import InputText from 'openvue/inputtext'
import { relativesPreview, createRelativesTree } from '../api/trees.js'
import { currentTreeId } from '../trees.js'
import { useNotify } from '../composables/feedback.js'
import { t, locale } from '../i18n/index.js'

/**
 * Neuer Stammbaum aus einer Ausgangsperson: alle Blutsverwandten (gemeinsamer Vorfahr) und
 * deren Partner, mit den benutzten Quellen, Archiven und Medien.
 */
const visible = defineModel('visible', { type: Boolean, default: false })
const props   = defineProps({ person: { type: Object, default: null } })   // { id, name }
const emit    = defineEmits(['open'])

const notify  = useNotify()
const name    = ref('')
const error   = ref('')
const preview = ref(null)
const loading = ref(false)
const created = ref(null)

const fmt = n => (n ?? 0).toLocaleString(locale.value)

watch(visible, async v => {
  if (!v || !props.person) return
  name.value    = t('relativesTree.defaultName', { name: props.person.name })
  error.value   = ''
  preview.value = null
  created.value = null
  try   { preview.value = await relativesPreview(currentTreeId.value, props.person.id) }
  catch (e) { error.value = e?.error || t('relativesTree.error') }
})

async function submit() {
  if (!name.value.trim()) { error.value = t('file.nameRequired'); return }
  loading.value = true
  error.value   = ''
  try {
    const res = await createRelativesTree(currentTreeId.value, props.person.id, name.value.trim())
    created.value = res
    notify.success(t('relativesTree.created', { name: res.tree.name }))
  } catch (e) {
    error.value = e?.error || t('relativesTree.error')
  } finally {
    loading.value = false
  }
}

function openNew() {
  emit('open', created.value.tree)
  visible.value = false
}
</script>

<template>
  <Dialog v-model:visible="visible" modal :draggable="false" :header="t('relativesTree.title')"
    :closable="!loading" :close-on-escape="!loading" :style="{ width: '34rem' }" :breakpoints="{ '600px': '95vw' }">
    <template v-if="!created">
      <p class="dialog-intro">{{ t('relativesTree.intro', { name: person?.name ?? '' }) }}</p>

      <p v-if="!preview && !error" class="muted"><i class="pi pi-spin pi-spinner" /> {{ t('relativesTree.counting') }}</p>
      <dl v-if="preview" class="view-fields relatives-preview">
        <dt>{{ t('relativesTree.blood') }}</dt><dd><strong>{{ fmt(preview.blood) }}</strong>
          <span class="muted">({{ t('relativesTree.bloodDetail', { a: fmt(preview.ancestors), d: fmt(preview.descendants) }) }})</span></dd>
        <dt>{{ t('relativesTree.spouses') }}</dt><dd><strong>{{ fmt(preview.spouses) }}</strong></dd>
        <dt>{{ t('relativesTree.total') }}</dt><dd><strong>{{ fmt(preview.persons) }}</strong> {{ t('relativesTree.persons') }},
          {{ fmt(preview.families) }} {{ t('relativesTree.families') }}</dd>
      </dl>

      <form class="form-group" novalidate @submit.prevent="submit">
        <label for="rel-tree-name">{{ t('relativesTree.name') }}</label>
        <InputText id="rel-tree-name" v-model="name" :invalid="!!error" fluid @input="error = ''" />
        <small v-if="error" class="error-msg">{{ error }}</small>
        <small class="field-hint">{{ t('relativesTree.hint') }}</small>
      </form>
    </template>

    <template v-else>
      <p class="dialog-intro"><i class="pi pi-check-circle" style="color: var(--color-success)" />
        {{ t('relativesTree.done', { name: created.tree.name, persons: fmt(created.stats.persons), families: fmt(created.stats.families) }) }}</p>
      <p v-if="created.stats.media" class="muted">{{ t('relativesTree.doneMedia', { sources: fmt(created.stats.sources), media: fmt(created.stats.media) }) }}</p>
    </template>

    <template #footer>
      <template v-if="!created">
        <Button :label="t('action.cancel')" severity="secondary" outlined :disabled="loading" @click="visible = false" />
        <Button :label="loading ? t('relativesTree.creating') : t('relativesTree.create')" icon="pi pi-sitemap"
          :loading="loading" :disabled="!preview" @click="submit" />
      </template>
      <template v-else>
        <Button :label="t('action.close')" severity="secondary" outlined @click="visible = false" />
        <Button :label="t('relativesTree.open')" icon="pi pi-folder-open" @click="openNew" />
      </template>
    </template>
  </Dialog>
</template>
