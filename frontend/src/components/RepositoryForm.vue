<script setup>
import { ref, reactive, computed, watch } from 'vue'
import InputText from 'openvue/inputtext'
import Textarea from 'openvue/textarea'
import Button from 'openvue/button'
import LastChange from './LastChange.vue'
import { createRepository, updateRepository } from '../api/repositories.js'
import { useNotify } from '../composables/feedback.js'
import { t } from '../i18n/index.js'
import { canEdit } from '../auth.js'

/** Archiv / Aufbewahrungsort (GEDCOM REPO) anlegen oder bearbeiten */
const props = defineProps({ editRepository: { type: Object, default: null } })
const emit  = defineEmits(['saved', 'cancel-edit', 'open-source'])

const notify = useNotify()

const FIELDS = ['name', 'address', 'postal_code', 'city', 'country', 'phone', 'email', 'website', 'notes']
const blank  = () => Object.fromEntries(FIELDS.map(f => [f, '']))

const form    = reactive(blank())
const error   = ref('')
const loading = ref(false)

const isEditing = computed(() => !!props.editRepository)
const sources   = computed(() => props.editRepository?.sources ?? [])

watch(() => props.editRepository, repo => {
  Object.assign(form, blank(), repo ? Object.fromEntries(FIELDS.map(f => [f, repo[f] ?? ''])) : {})
  error.value = ''
}, { immediate: true })

async function submit() {
  if (!form.name.trim()) { error.value = t('repository.nameRequired'); return }
  loading.value = true
  try {
    const repo = isEditing.value
      ? await updateRepository(props.editRepository.id, { ...form })
      : await createRepository({ ...form })
    notify.success(t('repository.saved', { name: repo.name }))
    emit('saved', repo)
    if (!isEditing.value) Object.assign(form, blank())
  } catch {
    notify.error(t('action.saveError'))
  } finally {
    loading.value = false
  }
}

function reset() {
  if (isEditing.value) emit('cancel-edit')
  else { Object.assign(form, blank()); error.value = '' }
}

const websiteHref = computed(() => /^https?:\/\//i.test(form.website) ? form.website : form.website ? 'https://' + form.website : '')
</script>

<template>
  <div class="card">
    <h2 class="card-title">{{ isEditing ? (canEdit ? t('repository.editTitle') : form.name) : t('repository.newTitle') }}</h2>

    <form :class="{ 'read-only': !canEdit }" novalidate @submit.prevent="submit">
      <fieldset>
        <legend>{{ t('repository.sections.main') }}</legend>
        <div class="form-row">
          <div class="form-group span-2">
            <label for="repo_name">{{ t('repository.fields.name') }} *</label>
            <InputText id="repo_name" v-model.trim="form.name" :invalid="!!error" :placeholder="t('repository.fields.namePlaceholder')"
              fluid @input="error = ''" />
            <small v-if="error" class="error-msg">{{ error }}</small>
          </div>
        </div>
      </fieldset>

      <fieldset>
        <legend>{{ t('repository.sections.address') }}</legend>
        <div class="form-row">
          <div class="form-group span-2">
            <label for="repo_addr">{{ t('repository.fields.address') }}</label>
            <Textarea id="repo_addr" v-model="form.address" rows="1" auto-resize :placeholder="t('repository.fields.addressPlaceholder')" fluid />
          </div>
          <div class="form-group">
            <label for="repo_post">{{ t('repository.fields.postalCode') }}</label>
            <InputText id="repo_post" v-model.trim="form.postal_code" fluid />
          </div>
          <div class="form-group">
            <label for="repo_city">{{ t('repository.fields.city') }}</label>
            <InputText id="repo_city" v-model.trim="form.city" fluid />
          </div>
          <div class="form-group span-2">
            <label for="repo_ctry">{{ t('repository.fields.country') }}</label>
            <InputText id="repo_ctry" v-model.trim="form.country" fluid />
          </div>
        </div>
      </fieldset>

      <fieldset>
        <legend>{{ t('repository.sections.contact') }}</legend>
        <div class="form-row">
          <div class="form-group">
            <label for="repo_phon">{{ t('repository.fields.phone') }}</label>
            <InputText id="repo_phon" v-model.trim="form.phone" fluid />
          </div>
          <div class="form-group">
            <label for="repo_mail">{{ t('repository.fields.email') }}</label>
            <InputText id="repo_mail" v-model.trim="form.email" type="email" fluid />
          </div>
          <div class="form-group span-2">
            <label for="repo_www">{{ t('repository.fields.website') }}</label>
            <div class="repo-select-row">
              <InputText id="repo_www" v-model.trim="form.website" placeholder="https://…" fluid />
              <a v-if="websiteHref" :href="websiteHref" target="_blank" rel="noopener" class="repo-link"
                :title="t('repository.openWebsite')"><i class="pi pi-external-link" /></a>
            </div>
          </div>
        </div>
      </fieldset>

      <fieldset>
        <legend>{{ t('person.sections.notes') }}</legend>
        <Textarea v-model="form.notes" rows="2" auto-resize :aria-label="t('person.notes.label')" fluid />
      </fieldset>

      <!-- Quellen in diesem Archiv -->
      <fieldset v-if="isEditing">
        <legend>{{ t('repository.sources', { n: sources.length }) }}</legend>
        <p v-if="!sources.length" class="field-hint">{{ t('repository.noSources') }}</p>
        <ul v-else class="repo-sources">
          <li v-for="s in sources" :key="s.id">
            <button type="button" class="repo-source" @click="emit('open-source', s.id)">
              <i class="pi pi-book" />
              <span class="repo-source-title">{{ s.title }}</span>
              <span v-if="canEdit && (s.call_number)" class="repo-source-caln">{{ s.call_number }}</span>
            </button>
          </li>
        </ul>
      </fieldset>

      <div class="form-actions">
        <Button :label="isEditing ? t('action.cancel') : t('action.reset')" severity="secondary" outlined @click="reset" />
        <Button type="submit" icon="pi pi-check" :loading="loading"
          :label="loading ? t('action.saving') : (isEditing ? t('action.saveChanges') : t('action.save'))" />
      </div>
    </form>
    <LastChange type="repository" :id="editRepository?.id" :label="editRepository?.name" :stamp="editRepository" />
  </div>
</template>
