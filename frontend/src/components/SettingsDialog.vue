<script setup>
import { ref, watch } from 'vue'
import Dialog from 'openvue/dialog'
import Button from 'openvue/button'
import InputText from 'openvue/inputtext'
import Checkbox from 'openvue/checkbox'
import { fetchSettings, saveSettings } from '../api/system.js'
import { legalLinks, siteName } from '../auth.js'
import { useNotify } from '../composables/feedback.js'
import { t } from '../i18n/index.js'

// Einstellungen der Installation (nur Admin): Name der Seite, Links auf Impressum und Datenschutzerklärung des Betreibers
const visible = defineModel('visible', { type: Boolean, default: false })
const notify  = useNotify()

const form    = ref({ site_name: '', imprint_url: '', privacy_url: '', share_url: '' })
const errors  = ref({})
const loading = ref(false)

watch(visible, async v => {
  if (!v) return
  errors.value = {}
  try   { form.value = await fetchSettings() }
  catch { notify.error(t('settings.loadError')) }
})

async function submit() {
  errors.value  = {}
  loading.value = true
  try {
    const saved = await saveSettings(form.value)
    const { site_name, share_url, ...links } = saved
    siteName.value   = site_name ?? ''
    legalLinks.value = Object.fromEntries(Object.entries(links).filter(([, v]) => v))
    notify.success(t('settings.saved'))
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
  <Dialog v-model:visible="visible" modal :draggable="false" :header="t('settings.title')"
    :style="{ width: '32rem' }" :breakpoints="{ '600px': '95vw' }">
    <form class="settings-form" novalidate @submit.prevent="submit">
      <div class="form-group">
        <label for="set-site-name">{{ t('settings.siteName') }}</label>
        <InputText id="set-site-name" v-model="form.site_name" maxlength="80" :placeholder="t('settings.siteNamePlaceholder')" fluid
          :invalid="!!errors.site_name" />
        <small v-if="errors.site_name" class="error-msg">{{ errors.site_name }}</small>
        <small v-else class="muted">{{ t('settings.siteNameHint') }}</small>
      </div>
      <p class="muted dialog-intro">{{ t('settings.legalIntro') }}</p>
      <div class="form-group">
        <label for="set-imprint">{{ t('settings.imprintUrl') }}</label>
        <InputText id="set-imprint" v-model="form.imprint_url" type="url" placeholder="https://…" fluid
          :invalid="!!errors.imprint_url" />
        <small v-if="errors.imprint_url" class="error-msg">{{ t('settings.invalidUrl') }}</small>
      </div>
      <div class="form-group">
        <label for="set-privacy">{{ t('settings.privacyUrl') }}</label>
        <InputText id="set-privacy" v-model="form.privacy_url" type="url" placeholder="https://…" fluid
          :invalid="!!errors.privacy_url" />
        <small v-if="errors.privacy_url" class="error-msg">{{ t('settings.invalidUrl') }}</small>
      </div>
      <!-- Adresse bei der Update-Abfrage mitteilen (bei öffentlichen Bäumen geschieht das ohnehin) -->
      <div class="settings-share">
        <label class="settings-check">
          <Checkbox v-model="form.share_url" binary true-value="1" false-value="" input-id="set-share-url" />
          <span>{{ t('settings.shareUrl') }}</span>
        </label>
        <small class="muted">{{ t('settings.shareUrlHint') }}</small>
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
.settings-form { display: flex; flex-direction: column; gap: 0.9rem; }
.settings-form .form-group { margin: 0; }
.settings-form .dialog-intro { margin-bottom: 0; }
.settings-share { display: flex; flex-direction: column; gap: .3rem; padding-top: .6rem; border-top: 1px solid var(--color-border); }
.settings-check { display: flex; align-items: center; gap: .5rem; cursor: pointer; }
</style>
