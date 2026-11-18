<script setup>
import { ref, computed, watch } from 'vue'
import Dialog from 'openvue/dialog'
import Button from 'openvue/button'
import InputText from 'openvue/inputtext'
import Password from 'openvue/password'
import { login, setup } from '../auth.js'
import { t } from '../i18n/index.js'

// setupMode: Es gibt noch keinen Benutzer – ersten Administrator anlegen (lässt sich nicht schließen)
const visible = defineModel('visible', { type: Boolean, default: false })
const props   = defineProps({ setupMode: { type: Boolean, default: false } })
const emit    = defineEmits(['done'])

const form    = ref({})
const errors  = ref({})
const error   = ref('')
const loading = ref(false)

const header = computed(() => props.setupMode ? t('auth.setupTitle') : t('auth.loginTitle'))

watch(visible, v => {
  if (!v) return
  form.value = { username: '', display_name: '', password: '', password2: '' }
  errors.value = {}
  error.value = ''
}, { immediate: true })

async function submit() {
  errors.value = {}
  error.value  = ''
  if (props.setupMode && form.value.password !== form.value.password2) {
    errors.value = { password2: t('auth.passwordMismatch') }
    return
  }
  loading.value = true
  try {
    const u = props.setupMode
      ? await setup({ username: form.value.username, display_name: form.value.display_name, password: form.value.password })
      : await login(form.value.username, form.value.password)
    visible.value = false
    emit('done', u)
  } catch (e) {
    errors.value = e?.errors ?? {}
    error.value  = e?.errors ? ''
      : e?.code === 'too_many_attempts' ? t('auth.tooManyAttempts', { minutes: Math.ceil((e.retry_after ?? 60) / 60) })
      : props.setupMode ? t('action.saveError') : t('auth.loginFailed')
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <Dialog v-model:visible="visible" modal :draggable="false" :header="header"
    :closable="!setupMode" :close-on-escape="!setupMode"
    :style="{ width: '26rem' }" :breakpoints="{ '600px': '95vw' }">
    <form class="auth-form" novalidate @submit.prevent="submit">
      <p v-if="setupMode" class="muted dialog-intro">{{ t('auth.setupIntro') }}</p>

      <div class="form-group">
        <label for="auth-user">{{ t('auth.username') }}</label>
        <InputText id="auth-user" v-model="form.username" autocomplete="username" autofocus fluid
          :invalid="!!errors.username" />
        <small v-if="errors.username" class="error-msg">{{ errors.username }}</small>
      </div>

      <div v-if="setupMode" class="form-group">
        <label for="auth-display">{{ t('auth.displayName') }}</label>
        <InputText id="auth-display" v-model="form.display_name" autocomplete="name" fluid />
      </div>

      <div class="form-group">
        <label for="auth-pass">{{ t('auth.password') }}</label>
        <Password input-id="auth-pass" v-model="form.password" :feedback="false" toggle-mask fluid
          :autocomplete="setupMode ? 'new-password' : 'current-password'" :invalid="!!errors.password" />
        <small v-if="errors.password" class="error-msg">{{ errors.password }}</small>
      </div>

      <div v-if="setupMode" class="form-group">
        <label for="auth-pass2">{{ t('auth.passwordRepeat') }}</label>
        <Password input-id="auth-pass2" v-model="form.password2" :feedback="false" toggle-mask fluid
          autocomplete="new-password" :invalid="!!errors.password2" />
        <small v-if="errors.password2" class="error-msg">{{ errors.password2 }}</small>
      </div>

      <small v-if="error" class="error-msg">{{ error }}</small>
      <button type="submit" hidden />
    </form>

    <template #footer>
      <Button v-if="!setupMode" :label="t('action.cancel')" severity="secondary" outlined @click="visible = false" />
      <Button :label="setupMode ? t('auth.setupSubmit') : t('auth.login')" icon="pi pi-sign-in" :loading="loading" @click="submit" />
    </template>
  </Dialog>
</template>

<style scoped>
.auth-form { display: flex; flex-direction: column; gap: 0.9rem; }
.auth-form .form-group { margin: 0; }
</style>
