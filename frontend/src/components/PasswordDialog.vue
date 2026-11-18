<script setup>
import { ref, watch } from 'vue'
import Dialog from 'openvue/dialog'
import Button from 'openvue/button'
import Password from 'openvue/password'
import { changePassword } from '../auth.js'
import { useNotify } from '../composables/feedback.js'
import { t } from '../i18n/index.js'

const visible = defineModel('visible', { type: Boolean, default: false })
const notify  = useNotify()

const form    = ref({})
const errors  = ref({})
const loading = ref(false)

watch(visible, v => { if (v) { form.value = { current: '', password: '', password2: '' }; errors.value = {} } })

async function submit() {
  errors.value = {}
  if (form.value.password !== form.value.password2) { errors.value = { password2: t('auth.passwordMismatch') }; return }
  loading.value = true
  try {
    await changePassword(form.value.current, form.value.password)
    notify.success(t('auth.passwordChanged'))
    visible.value = false
  } catch (e) {
    if (e?.errors) errors.value = e.errors
    else notify.error(t('action.saveError'))
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <Dialog v-model:visible="visible" modal :draggable="false" :header="t('auth.changePassword')"
    :style="{ width: '26rem' }" :breakpoints="{ '600px': '95vw' }">
    <form class="auth-form" novalidate @submit.prevent="submit">
      <div class="form-group">
        <label for="pw-current">{{ t('auth.currentPassword') }}</label>
        <Password input-id="pw-current" v-model="form.current" :feedback="false" toggle-mask fluid
          autocomplete="current-password" :invalid="!!errors.current" />
        <small v-if="errors.current" class="error-msg">{{ errors.current }}</small>
      </div>
      <div class="form-group">
        <label for="pw-new">{{ t('auth.newPassword') }}</label>
        <Password input-id="pw-new" v-model="form.password" :feedback="false" toggle-mask fluid
          autocomplete="new-password" :invalid="!!errors.password" />
        <small v-if="errors.password" class="error-msg">{{ errors.password }}</small>
      </div>
      <div class="form-group">
        <label for="pw-new2">{{ t('auth.passwordRepeat') }}</label>
        <Password input-id="pw-new2" v-model="form.password2" :feedback="false" toggle-mask fluid
          autocomplete="new-password" :invalid="!!errors.password2" />
        <small v-if="errors.password2" class="error-msg">{{ errors.password2 }}</small>
      </div>
      <button type="submit" hidden />
    </form>
    <template #footer>
      <Button :label="t('action.cancel')" severity="secondary" outlined @click="visible = false" />
      <Button :label="t('action.save')" icon="pi pi-check" :loading="loading" @click="submit" />
    </template>
  </Dialog>
</template>

<style scoped>
.auth-form { display: flex; flex-direction: column; gap: 0.9rem; }
.auth-form .form-group { margin: 0; }
</style>
