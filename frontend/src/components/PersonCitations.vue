<script setup>
// Quellen der Person, die nicht an ein Ereignis gebunden sind (INDI.SOUR); eine zum Bearbeiten aufgeklappt
import { ref } from 'vue'
import Button from 'openvue/button'
import CitationView from './CitationView.vue'
import CitationFields from './CitationFields.vue'
import { t } from '../i18n/index.js'
import { canEdit } from '../auth.js'
import { blankCitation } from '../personModel.js'

const citations = defineModel({ type: Array, required: true })
const open = ref(null)   // Index der Quellenangabe in Bearbeitung

function add()     { citations.value.push(blankCitation()); open.value = citations.value.length - 1 }
function remove(i) { citations.value.splice(i, 1); open.value = null }
</script>

<template>
  <fieldset>
    <legend>{{ t('person.sections.sources') }}</legend>
    <template v-for="(cit, ci) in citations" :key="ci">
      <div v-if="open !== ci" class="name-entry name-entry-line">
        <div class="name-line view-cit-line">
          <CitationView :citation="cit" />
          <span class="name-line-actions" @click.stop>
            <Button v-if="canEdit" icon="pi pi-pencil" text rounded size="small"
              :aria-label="t('action.edit')" v-tooltip.top="t('action.edit')" @click="open = ci" />
            <Button v-if="canEdit" icon="pi pi-trash" text rounded size="small" severity="danger"
              :aria-label="t('action.remove')" v-tooltip.top="t('action.remove')" @click="remove(ci)" />
          </span>
        </div>
      </div>
      <div v-else class="sub-card person-cit">
        <CitationFields :citation="cit" :id-prefix="`p${ci}_`" @remove="remove(ci)">
          <div class="sub-wide asso-done">
            <Button :label="t('person.nameFields.done')" icon="pi pi-check" size="small" outlined @click="open = null" />
          </div>
        </CitationFields>
      </div>
    </template>
    <p v-if="!citations.length" class="field-hint">{{ t('person.sourcesHint') }}</p>
    <Button v-if="canEdit" :label="t('person.citations.add')" icon="pi pi-book" text size="small" class="slot-add" @click="add" />
  </fieldset>
</template>
