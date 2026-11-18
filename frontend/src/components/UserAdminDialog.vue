<script setup>
import { ref, computed, watch } from 'vue'
import Dialog from 'openvue/dialog'
import Button from 'openvue/button'
import InputText from 'openvue/inputtext'
import Password from 'openvue/password'
import SelectButton from 'openvue/selectbutton'
import { fetchUsers, createUser, updateUser, deleteUser } from '../api/users.js'
import { user as me, userLabel } from '../auth.js'
import { useAsk, useNotify } from '../composables/feedback.js'
import { t } from '../i18n/index.js'

const visible = defineModel('visible', { type: Boolean, default: false })
const ask    = useAsk()
const notify = useNotify()

const users   = ref([])
const loading = ref(false)
const editing = ref(null)   // { id?, username, display_name, role, password }
const errors  = ref({})
const saving  = ref(false)

const roles = computed(() => [
  { value: 'editor', label: t('auth.roles.editor') },
  { value: 'admin',  label: t('auth.roles.admin') },
])

async function load() {
  loading.value = true
  try   { users.value = await fetchUsers() }
  catch { notify.error(t('auth.users.loadError')) }
  finally { loading.value = false }
}

watch(visible, v => { if (v) { editing.value = null; load() } })

function startNew()  { errors.value = {}; editing.value = { username: '', display_name: '', role: 'editor', password: '' } }
function startEdit(u) { errors.value = {}; editing.value = { ...u, display_name: u.display_name ?? '', password: '' } }

async function save() {
  saving.value = true
  errors.value = {}
  const data = { username: editing.value.username, display_name: editing.value.display_name,
                 role: editing.value.role, password: editing.value.password }
  try {
    if (editing.value.id) await updateUser(editing.value.id, data)
    else                  await createUser(data)
    notify.success(t('auth.users.saved', { name: data.username }))
    editing.value = null
    await load()
  } catch (e) {
    if (e?.errors) errors.value = e.errors
    else notify.error(e?.error ?? t('action.saveError'))
  } finally {
    saving.value = false
  }
}

async function remove(u) {
  if (!(await ask(t('auth.users.deleteConfirm', { name: u.username })))) return
  try {
    await deleteUser(u.id)
    notify.success(t('auth.users.deleted', { name: u.username }))
    await load()
  } catch (e) {
    notify.error(e?.error ?? t('action.deleteError'))
  }
}

function formatDate(s) {
  if (!s) return t('auth.users.never')
  const d = new Date(String(s).replace(' ', 'T'))
  return isNaN(d) ? '' : d.toLocaleString()
}
</script>

<template>
  <Dialog v-model:visible="visible" modal :draggable="false" :header="t('auth.users.title')"
    :style="{ width: '40rem' }" :breakpoints="{ '700px': '95vw' }">

    <form v-if="editing" class="user-form" novalidate @submit.prevent="save">
      <div class="form-row">
        <div class="form-group">
          <label for="u-name">{{ t('auth.username') }}</label>
          <InputText id="u-name" v-model="editing.username" autocomplete="off" fluid autofocus :invalid="!!errors.username" />
          <small v-if="errors.username" class="error-msg">{{ errors.username }}</small>
        </div>
        <div class="form-group">
          <label for="u-display">{{ t('auth.displayName') }}</label>
          <InputText id="u-display" v-model="editing.display_name" autocomplete="off" fluid />
        </div>
      </div>
      <div class="form-group">
        <label>{{ t('auth.role') }}</label>
        <SelectButton v-model="editing.role" :options="roles" option-value="value" option-label="label" :allow-empty="false" />
        <small v-if="errors.role" class="error-msg">{{ errors.role }}</small>
        <small class="muted">{{ t('auth.roleHint.' + editing.role) }}</small>
      </div>
      <div class="form-group">
        <label for="u-pass">{{ editing.id ? t('auth.users.newPassword') : t('auth.password') }}</label>
        <Password input-id="u-pass" v-model="editing.password" :feedback="false" toggle-mask fluid
          autocomplete="new-password" :invalid="!!errors.password" />
        <small v-if="errors.password" class="error-msg">{{ errors.password }}</small>
      </div>
      <div class="form-actions">
        <Button :label="t('action.cancel')" severity="secondary" outlined @click="editing = null" />
        <Button type="submit" :label="t('action.save')" icon="pi pi-check" :loading="saving" />
      </div>
    </form>

    <template v-else>
      <p v-if="loading && !users.length" class="muted">{{ t('common.loading') }}</p>
      <ul v-else class="user-list">
        <li v-for="u in users" :key="u.id" class="user-item">
          <i :class="['pi', u.role === 'admin' ? 'pi-shield' : 'pi-user']" />
          <div class="user-info">
            <div class="user-name">
              {{ userLabel(u) }}
              <span v-if="u.display_name" class="muted">({{ u.username }})</span>
              <span v-if="u.id === me?.id" class="tree-file-badge">{{ t('auth.users.you') }}</span>
            </div>
            <div class="muted user-meta">{{ t('auth.roles.' + u.role) }} · {{ t('auth.users.lastLogin') }}: {{ formatDate(u.last_login) }}</div>
          </div>
          <Button icon="pi pi-pencil" text rounded size="small" :aria-label="t('action.edit')" v-tooltip.top="t('action.edit')" @click="startEdit(u)" />
          <Button icon="pi pi-trash" text rounded size="small" severity="danger" :disabled="u.id === me?.id"
            :aria-label="t('action.delete')" v-tooltip.top="t('action.delete')" @click="remove(u)" />
        </li>
      </ul>
    </template>

    <template #footer>
      <Button v-if="!editing" :label="t('auth.users.new')" icon="pi pi-plus" @click="startNew" />
      <Button :label="t('action.close')" severity="secondary" outlined @click="visible = false" />
    </template>
  </Dialog>
</template>

<style scoped>
.user-form { display: flex; flex-direction: column; gap: 0.9rem; }
.user-form .form-group { margin: 0; display: flex; flex-direction: column; gap: 0.3rem; }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 0.9rem; }
@media (max-width: 600px) { .form-row { grid-template-columns: 1fr; } }
.form-actions { display: flex; justify-content: flex-end; gap: 0.5rem; }
.user-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 0.25rem; }
.user-item { display: flex; align-items: center; gap: 0.75rem; padding: 0.5rem 0.6rem; border-radius: 8px; }
.user-item:hover { background: var(--p-surface-50); }
.user-item > .pi { color: var(--p-primary-color); }
.user-info { flex: 1; min-width: 0; }
.user-name { font-weight: 600; display: flex; gap: 0.4rem; align-items: center; flex-wrap: wrap; }
.user-name .muted { font-weight: 400; }
.user-meta { font-size: 0.85rem; }
</style>
