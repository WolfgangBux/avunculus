<script setup>
import { ref, computed, watch } from 'vue'
import Dialog from 'openvue/dialog'
import Button from 'openvue/button'
import Checkbox from 'openvue/checkbox'
import { installUpdate } from '../api/system.js'
import { updateStatus, showUpdate, checkUpdates } from '../composables/updates.js'
import { t, locale } from '../i18n/index.js'
import { renderMarkdown } from '../markdown.js'

/**
 * Neue Version anzeigen und installieren (nur Admin). Der Server lädt das Paket, prüft es und tauscht
 * die Dateien aus; die Datenbank-Migrationen laufen beim Neuladen der Seite von selbst.
 */
const status    = computed(() => updateStatus.value)
const backedUp  = ref(false)
const state     = ref('idle')   // idle | checking | installing | done | error
const error     = ref('')

watch(showUpdate, v => { if (v) { backedUp.value = false; error.value = ''; state.value = 'idle' } })

async function refresh() {
  state.value = 'checking'
  await checkUpdates(true)
  state.value = 'idle'
}

async function install() {
  state.value = 'installing'
  error.value = ''
  try {
    await installUpdate(status.value.latest)
    state.value = 'done'
    setTimeout(() => location.reload(), 1500)   // neuer Code; Migrationen laufen bei der ersten Anfrage
  } catch (e) {
    state.value = 'error'
    error.value = e?.error ?? t('update.failed')
  }
}

// sicher: renderMarkdown maskiert zuerst allen HTML-Code
const notesHtml = computed(() => renderMarkdown(status.value?.notes))

const checkedAt = computed(() => status.value?.checked_at
  ? new Date(status.value.checked_at * 1000).toLocaleString(locale.value, { dateStyle: 'medium', timeStyle: 'short' }) : '')
</script>

<template>
  <Dialog v-model:visible="showUpdate" modal :draggable="false" :header="t('update.title')"
    :closable="state !== 'installing'" :style="{ width: '38rem' }" :breakpoints="{ '700px': '95vw' }">

    <dl class="update-versions">
      <dt>{{ t('update.current') }}</dt><dd>{{ status?.current }}</dd>
      <template v-if="status?.latest">
        <dt>{{ t('update.latest') }}</dt>
        <dd>{{ status.latest }} <a v-if="status.url" :href="status.url" target="_blank" rel="noopener">{{ t('update.releasePage') }}</a></dd>
      </template>
    </dl>

    <p v-if="status && !status.check" class="muted">{{ t('update.disabled') }}</p>
    <div v-else-if="status?.error" class="alert alert-error">{{ status.error }}</div>

    <template v-if="status?.newer">
      <div v-if="status.notes" class="update-notes" v-html="notesHtml" />

      <template v-if="status.can_install">
        <p class="update-hint">{{ t('update.howItWorks') }}</p>
        <label class="update-confirm">
          <Checkbox v-model="backedUp" binary :disabled="state === 'installing' || state === 'done'" />
          <span>{{ t('update.backupConfirm') }}</span>
        </label>
      </template>
      <div v-else class="alert alert-warning">
        {{ t('update.blocked.' + (status.blocker || 'unknown')) }} {{ t('update.manual') }}
      </div>
    </template>
    <p v-else-if="status?.latest" class="muted">{{ t('update.upToDate') }}</p>

    <!-- Transparenz: was die Update-Abfrage mitschickt (USAGE_STATS=0 schaltet es ab) -->
    <p v-if="status?.check" class="muted update-stats">{{ t(!status.stats ? 'update.statsOff' : status.shares_url ? 'update.statsOnUrl' : 'update.statsOn') }}</p>

    <div v-if="state === 'done'" class="alert alert-success">{{ t('update.done', { version: status.latest }) }}</div>
    <div v-if="error" class="alert alert-error">{{ error }}</div>

    <template #footer>
      <span v-if="checkedAt" class="muted update-checked">{{ t('update.checkedAt', { time: checkedAt }) }}</span>
      <Button v-if="status?.check" :label="t('update.checkNow')" icon="pi pi-refresh" text
        :loading="state === 'checking'" :disabled="state === 'installing' || state === 'done'" @click="refresh" />
      <Button v-if="status?.newer && status.can_install" :label="t('update.install', { version: status.latest })"
        icon="pi pi-download" :loading="state === 'installing'" :disabled="!backedUp || state === 'done'" @click="install" />
      <Button v-else :label="t('action.close')" severity="secondary" outlined @click="showUpdate = false" />
    </template>
  </Dialog>
</template>

<style scoped>
.update-versions { display: grid; grid-template-columns: max-content 1fr; gap: .25rem 1rem; margin: 0 0 .9rem; }
.update-versions dt { color: var(--color-muted); }
.update-versions dd { margin: 0; font-weight: 600; }
.update-versions a { font-weight: 400; margin-left: .5rem; }
.update-notes {
  max-height: 16rem; overflow-y: auto; font-size: .85rem; overflow-wrap: anywhere;
  padding: .6rem .8rem; border: 1px solid var(--color-border); border-radius: var(--radius-sm); background: var(--color-surface-alt);
}
.update-notes :deep(:is(h3, h4, h5, h6)) { font-size: .9rem; margin: .6rem 0 .3rem; }
.update-notes :deep(:first-child) { margin-top: 0; }
.update-notes :deep(:last-child) { margin-bottom: 0; }
.update-notes :deep(:is(p, ul, ol)) { margin: .3rem 0; }
.update-notes :deep(:is(ul, ol)) { padding-left: 1.3rem; }
.update-notes :deep(code) { font-size: .8rem; background: var(--color-surface); padding: 0 .2rem; border-radius: 3px; }
.update-hint { font-size: .85rem; margin: .8rem 0 .5rem; }
.update-confirm { display: flex; align-items: flex-start; gap: .5rem; font-size: .85rem; cursor: pointer; }
.update-stats { font-size: .78rem; margin-top: .75rem; }
.update-checked { margin-right: auto; font-size: .78rem; align-self: center; }
</style>
