<script>
import { ref, computed, watch, defineComponent } from 'vue'
import Dialog from 'openvue/dialog'
import Button from 'openvue/button'
import Message from 'openvue/message'
import PersonSearch from './PersonSearch.vue'
import FamilyEvents from './FamilyEvents.vue'
import {
  fetchPersonFamilies,
  createFamily,
  addFamilyMember,
  removeFamilyMember,
  deleteFamily,
} from '../api/families.js'
import { useAsk } from '../composables/feedback.js'
import { t } from '../i18n/index.js'
import { extractYear } from '../gedcom.js'

function nameLabel(p) {
  const surname = [p.surname_prefix, p.surname].filter(Boolean).join(' ')
  return [surname, p.given_name].filter(Boolean).join(', ') || '–'
}

function roleFor(sex) {
  return sex === 'F' ? 'WIFE' : 'HUSB'
}

export default defineComponent({
  components: { Dialog, Button, Message, PersonSearch, FamilyEvents },

  props: {
    person: { type: Object, required: true },
  },
  emits: ['close', 'changed', 'edit-family'],

  setup(props, { emit }) {
    const ask = useAsk()
    const visible  = ref(true)
    const families = ref([])
    const loading  = ref(false)
    const error    = ref('')

    // { type: 'partner' | 'parent' | 'child', role?: 'HUSB'|'WIFE', familyId?: Number|null }
    const adding = ref(null)

    // quiet: ohne „Lade…“-Anzeige nachladen (nach kleinen Änderungen, verhindert Flackern)
    async function load(quiet = false) {
      if (!quiet) loading.value = true
      error.value   = ''
      try   { families.value = await fetchPersonFamilies(props.person.id) }
      catch { error.value = t('family.error.load') }
      finally { loading.value = false }
    }

    watch(() => props.person, () => load(), { immediate: true })

    const birthFamily     = computed(() => families.value.find(f => f.person_role === 'CHIL') ?? null)
    const partnerFamilies = computed(() => families.value.filter(f => f.person_role !== 'CHIL'))

    const allRelatedIds = computed(() => {
      const ids = new Set([props.person.id])
      for (const f of families.value)
        for (const m of f.members) ids.add(m.person_id)
      return [...ids]
    })

    function membersOf(family, role) {
      return family.members.filter(m => m.role === role)
    }

    function childrenOf(family) {
      return family.members.filter(m => m.role === 'CHIL')
    }

    function partnersOf(family) {
      return family.members.filter(m => m.role !== 'CHIL' && m.person_id !== props.person.id)
    }

    function parentLabel(role) { return t(role === 'HUSB' ? 'family.father' : 'family.mother') }

    async function onSelectPartner(p) {
      adding.value = null
      try {
        await createFamily({ members: [
          { person_id: props.person.id, role: roleFor(props.person.sex) },
          { person_id: p.id,            role: roleFor(p.sex) },
        ]})
        emit('changed')
        await load()
      } catch { error.value = t('family.error.addPartner') }
    }

    async function onSelectParent(p) {
      const role = adding.value?.role ?? roleFor(p.sex)
      adding.value = null
      try {
        if (birthFamily.value) {
          await addFamilyMember(birthFamily.value.id, { person_id: p.id, role })
        } else {
          await createFamily({ members: [
            { person_id: props.person.id, role: 'CHIL' },
            { person_id: p.id,            role },
          ]})
        }
        emit('changed')
        await load()
      } catch { error.value = t('family.error.addParent') }
    }

    async function onSelectChild(p) {
      const targetFamilyId = adding.value?.familyId ?? null
      adding.value = null
      try {
        if (targetFamilyId) {
          await addFamilyMember(targetFamilyId, { person_id: p.id, role: 'CHIL' })
        } else {
          await createFamily({ members: [
            { person_id: props.person.id, role: roleFor(props.person.sex) },
            { person_id: p.id,            role: 'CHIL' },
          ]})
        }
        emit('changed')
        await load()
      } catch { error.value = t('family.error.addChild') }
    }

    async function removeMember(familyId, personId) {
      if (!(await ask(t('family.removeConfirm'), { acceptLabel: t('action.remove') }))) return
      try {
        await removeFamilyMember(familyId, personId)
        emit('changed')
        await load()
      } catch { error.value = t('family.error.remove') }
    }

    async function removeFam(familyId) {
      if (!(await ask(t('family.deleteConfirm')))) return
      try {
        await deleteFamily(familyId)
        emit('changed')
        await load()
      } catch { error.value = t('family.error.delete') }
    }

    async function onEventsChanged() {
      emit('changed')
      await load(true)
    }

    return {
      t, visible, extractYear,
      families, loading, error, adding,
      birthFamily, partnerFamilies, allRelatedIds,
      membersOf, childrenOf, partnersOf, parentLabel,
      nameLabel,
      onSelectPartner, onSelectParent, onSelectChild,
      removeMember, removeFam, onEventsChanged,
      emit,
    }
  },
})
</script>

<template>
<Dialog v-model:visible="visible" modal :draggable="false" class="family-dialog"
  :style="{ width: '40rem' }" :breakpoints="{ '700px': '95vw' }" @after-hide="emit('close')">

  <template #header>
    <div class="family-dialog-header">
      <div :class="['avatar', 'sex-' + (person.sex || 'U')]" aria-hidden="true">
        <svg viewBox="0 0 32 32" fill="currentColor"><circle cx="16" cy="12" r="6"/><path d="M4 32c0-7 5.4-11 12-11s12 4 12 11z"/></svg>
      </div>
      <div>
        <div class="family-dialog-kicker">{{ t('family.title') }}</div>
        <div class="family-dialog-name">{{ nameLabel(person) }}</div>
      </div>
    </div>
  </template>

  <p v-if="loading" class="muted">{{ t('common.loading') }}</p>
  <Message v-if="error" severity="error" closable class="family-error" @close="error = ''">{{ error }}</Message>

  <div v-if="!loading" class="family-body">

    <!-- Elternfamilie -->
    <section class="fam-section">
      <h3 class="fam-section-title">{{ t('family.parents') }}</h3>

      <div v-if="birthFamily" class="chip-row">
        <template v-for="role in ['HUSB','WIFE']" :key="role">
          <div v-for="m in membersOf(birthFamily, role)" :key="m.person_id" :class="['member-chip', 'sex-' + (m.sex || 'U')]">
            <span class="chip-role">{{ parentLabel(role) }}</span>
            <span class="chip-name">{{ m.display_name }}</span>
            <span v-if="m.birth_date" class="chip-year muted">* {{ extractYear(m.birth_date) }}</span>
            <button class="chip-remove" :title="t('action.remove')" @click="removeMember(birthFamily.id, m.person_id)"><i class="pi pi-times" /></button>
          </div>
        </template>
      </div>
      <p v-else class="muted">{{ t('family.noParents') }}</p>

      <div v-if="birthFamily && membersOf(birthFamily, 'HUSB').length + membersOf(birthFamily, 'WIFE').length >= 2" class="fam-card fam-card-parents">
        <FamilyEvents :family="birthFamily" @changed="onEventsChanged" />
        <div class="add-row fam-card-actions">
          <Button :label="t('action.edit')" icon="pi pi-pencil" text size="small" @click="emit('edit-family', birthFamily.id)" />
        </div>
      </div>

      <div class="add-row">
        <template v-for="role in ['HUSB','WIFE']" :key="role">
          <Button v-if="!birthFamily || membersOf(birthFamily, role).length === 0"
            :label="parentLabel(role)" icon="pi pi-plus" text size="small"
            @click="adding = { type: 'parent', role }" />
        </template>
      </div>

      <div v-if="adding?.type === 'parent'" class="search-panel">
        <PersonSearch :exclude-ids="allRelatedIds" :placeholder="t('family.searchFor', { what: parentLabel(adding.role) })"
          @select="onSelectParent" @cancel="adding = null" />
      </div>
    </section>

    <!-- Eigene Familien -->
    <section class="fam-section">
      <h3 class="fam-section-title">{{ t('family.own') }}</h3>

      <div v-for="fam in partnerFamilies" :key="fam.id" class="fam-card">
        <div class="fam-row">
          <span class="fam-row-label">{{ t('family.partner') }}</span>
          <div class="chip-row">
            <div v-for="m in partnersOf(fam)" :key="m.person_id" :class="['member-chip', 'sex-' + (m.sex || 'U')]">
              <span class="chip-name">{{ m.display_name }}</span>
              <span v-if="m.birth_date" class="chip-year muted">* {{ extractYear(m.birth_date) }}</span>
              <button class="chip-remove" :title="t('action.remove')" @click="removeMember(fam.id, m.person_id)"><i class="pi pi-times" /></button>
            </div>
            <span v-if="partnersOf(fam).length === 0" class="muted">–</span>
          </div>
        </div>
        <div class="fam-row">
          <span class="fam-row-label">{{ t('family.children') }}</span>
          <div class="chip-row">
            <div v-for="m in childrenOf(fam)" :key="m.person_id" :class="['member-chip', 'sex-' + (m.sex || 'U')]">
              <span class="chip-name">{{ m.display_name }}</span>
              <span v-if="m.birth_date" class="chip-year muted">* {{ extractYear(m.birth_date) }}</span>
              <span v-if="m.pedigree && m.pedigree !== 'BIRTH'" class="pedigree-tag">{{ t('family.pedigree.' + m.pedigree) }}</span>
              <button class="chip-remove" :title="t('action.remove')" @click="removeMember(fam.id, m.person_id)"><i class="pi pi-times" /></button>
            </div>
            <span v-if="childrenOf(fam).length === 0" class="muted">–</span>
          </div>
        </div>
        <FamilyEvents :family="fam" @changed="onEventsChanged" />
        <div class="add-row fam-card-actions">
          <Button :label="t('action.edit')" icon="pi pi-pencil" text size="small" @click="emit('edit-family', fam.id)" />
          <Button :label="t('family.addChild')" icon="pi pi-plus" text size="small" @click="adding = { type: 'child', familyId: fam.id }" />
          <Button :label="t('family.deleteFamily')" icon="pi pi-trash" text size="small" severity="danger" @click="removeFam(fam.id)" />
        </div>
        <div v-if="adding?.type === 'child' && adding.familyId === fam.id" class="search-panel">
          <PersonSearch :exclude-ids="allRelatedIds" :placeholder="t('family.searchFor', { what: t('family.child') })"
            @select="onSelectChild" @cancel="adding = null" />
        </div>
      </div>

      <div class="add-row">
        <Button v-if="adding?.type !== 'partner'" :label="t('family.addPartner')" icon="pi pi-plus" text size="small"
          @click="adding = { type: 'partner' }" />
        <Button v-if="partnerFamilies.length === 0 && adding?.type !== 'child'" :label="t('family.addChild')" icon="pi pi-plus" text size="small"
          @click="adding = { type: 'child', familyId: null }" />
      </div>

      <div v-if="adding?.type === 'partner'" class="search-panel">
        <PersonSearch :exclude-ids="allRelatedIds" :placeholder="t('family.searchFor', { what: t('family.partner') })"
          @select="onSelectPartner" @cancel="adding = null" />
      </div>
      <div v-if="adding?.type === 'child' && adding.familyId === null" class="search-panel">
        <PersonSearch :exclude-ids="allRelatedIds" :placeholder="t('family.searchFor', { what: t('family.child') })"
          @select="onSelectChild" @cancel="adding = null" />
      </div>
    </section>
  </div>

  <template #footer>
    <Button :label="t('action.close')" severity="secondary" outlined @click="visible = false" />
  </template>
</Dialog>
</template>
