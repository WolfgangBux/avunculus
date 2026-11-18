<script setup>
import { ref, watch } from 'vue'
import Dialog from 'openvue/dialog'
import Button from 'openvue/button'
import InputText from 'openvue/inputtext'
import { createTree } from '../api/trees.js'
import { useNotify } from '../composables/feedback.js'
import { t } from '../i18n/index.js'

const visible = defineModel('visible', { type: Boolean, default: false })
const emit    = defineEmits(['created'])

const notify  = useNotify()
const name    = ref('')
const error   = ref('')
const loading = ref(false)

watch(visible, v => { if (v) { name.value = ''; error.value = '' } })

async function submit() {
  if (!name.value.trim()) { error.value = t('file.nameRequired'); return }
  loading.value = true
  try {
    const tree = await createTree(name.value.trim())
    notify.success(t('file.created', { name: tree.name }))
    emit('created', tree)
    visible.value = false
  } catch {
    notify.error(t('action.saveError'))
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <Dialog v-model:visible="visible" modal :draggable="false" :header="t('file.newTitle')"
    :style="{ width: '28rem' }" :breakpoints="{ '600px': '95vw' }">
    <form class="form-group" novalidate @submit.prevent="submit">
      <label for="new-tree-name">{{ t('file.name') }}</label>
      <InputText id="new-tree-name" v-model="name" :invalid="!!error" :placeholder="t('file.namePlaceholder')"
        autofocus fluid @input="error = ''" />
      <small v-if="error" class="error-msg">{{ error }}</small>
    </form>

    <template #footer>
      <Button :label="t('action.cancel')" severity="secondary" outlined @click="visible = false" />
      <Button :label="t('file.create')" icon="pi pi-check" :loading="loading" @click="submit" />
    </template>
  </Dialog>
</template>
