<script setup>
import { ref, watch } from 'vue'
import Dialog from 'openvue/dialog'
import Button from 'openvue/button'
import InputText from 'openvue/inputtext'
import { fetchTrees, renameTree, deleteTree, setTreePublic } from '../api/trees.js'
import { isAdmin } from '../auth.js'
import { useAsk, useNotify } from '../composables/feedback.js'
import { t } from '../i18n/index.js'

const visible = defineModel('visible', { type: Boolean, default: false })
const props   = defineProps({ currentId: { type: Number, default: null } })
const emit    = defineEmits(['open', 'changed', 'deleted'])

const ask    = useAsk()
const notify = useNotify()

const trees    = ref([])
const loading  = ref(false)
const editId   = ref(null)
const editName = ref('')

async function load() {
  loading.value = true
  try   { trees.value = await fetchTrees() }
  catch { notify.error(t('file.loadError')) }
  finally { loading.value = false }
}

watch(visible, v => { if (v) { editId.value = null; load() } })

function open(tree) {
  if (editId.value) return
  emit('open', tree)
  visible.value = false
}

function startRename(tree) {
  editId.value   = tree.id
  editName.value = tree.name
}

async function saveRename() {
  const name = editName.value.trim()
  if (!name) return
  try {
    await renameTree(editId.value, name)
    editId.value = null
    await load()
    emit('changed')
  } catch {
    notify.error(t('action.saveError'))
  }
}

async function remove(tree) {
  const ok = await ask(t('file.deleteConfirm', {
    name: tree.name, persons: tree.person_count, sources: tree.source_count,
  }))
  if (!ok) return
  try {
    await deleteTree(tree.id)
    notify.success(t('file.deleted', { name: tree.name }))
    await load()
    emit('deleted', tree)
  } catch {
    notify.error(t('file.deleteError'))
  }
}

async function togglePublic(tree) {
  try {
    await setTreePublic(tree.id, !tree.is_public)
    notify.success(t(tree.is_public ? 'privacy.madePrivate' : 'privacy.madePublic', { name: tree.name }))
    await load()
    emit('changed')
  } catch {
    notify.error(t('action.saveError'))
  }
}

function formatDate(s) {
  const d = new Date(String(s).replace(' ', 'T'))
  return isNaN(d) ? '' : d.toLocaleDateString()
}
</script>

<template>
  <Dialog v-model:visible="visible" modal :draggable="false" :header="t('file.openTitle')"
    :style="{ width: '38rem' }" :breakpoints="{ '700px': '95vw' }">

    <p v-if="loading && !trees.length" class="muted">{{ t('common.loading') }}</p>

    <ul v-else class="tree-file-list">
      <li v-for="tr in trees" :key="tr.id"
        :class="['tree-file', { current: tr.id === currentId }]"
        role="button" tabindex="0" @click="open(tr)" @keydown.enter="open(tr)">
        <div class="tree-file-icon"><i class="pi pi-sitemap" /></div>

        <div class="tree-file-info">
          <template v-if="editId === tr.id">
            <InputText v-model="editName" size="small" fluid autofocus
              @click.stop @keydown.enter.stop="saveRename" @keydown.esc.stop="editId = null" />
          </template>
          <template v-else>
            <div class="tree-file-name">
              {{ tr.name }}
              <span v-if="tr.id === currentId" class="tree-file-badge">{{ t('file.current') }}</span>
              <span v-if="tr.is_public && isAdmin" class="tree-file-badge tree-file-public" v-tooltip.top="t('privacy.publicHint')">
                <i class="pi pi-globe" /> {{ t('privacy.public') }}
              </span>
            </div>
            <div class="tree-file-meta">
              {{ t('file.meta', { persons: tr.person_count, families: tr.family_count, sources: tr.source_count }) }}
              · {{ formatDate(tr.created_at) }}
            </div>
          </template>
        </div>

        <div v-if="isAdmin" class="tree-file-actions" @click.stop>
          <template v-if="editId === tr.id">
            <Button icon="pi pi-check" text rounded size="small" :aria-label="t('action.save')" @click="saveRename" />
            <Button icon="pi pi-times" text rounded size="small" severity="secondary" :aria-label="t('action.cancel')" @click="editId = null" />
          </template>
          <template v-else>
            <Button :icon="tr.is_public ? 'pi pi-globe' : 'pi pi-lock'" text rounded size="small"
              :severity="tr.is_public ? undefined : 'secondary'"
              :aria-label="tr.is_public ? t('privacy.makePrivate') : t('privacy.makePublic')"
              v-tooltip.top="tr.is_public ? t('privacy.makePrivate') : t('privacy.makePublic')" @click="togglePublic(tr)" />
            <Button icon="pi pi-pencil" text rounded size="small" :aria-label="t('file.rename')" v-tooltip.top="t('file.rename')" @click="startRename(tr)" />
            <Button icon="pi pi-trash" text rounded size="small" severity="danger" :aria-label="t('action.delete')" v-tooltip.top="t('action.delete')" @click="remove(tr)" />
          </template>
        </div>
      </li>
    </ul>

    <template #footer>
      <Button :label="t('action.close')" severity="secondary" outlined @click="visible = false" />
    </template>
  </Dialog>
</template>
