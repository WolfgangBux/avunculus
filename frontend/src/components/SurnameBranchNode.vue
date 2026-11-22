<script setup>
import { computed, inject } from 'vue'
import { nameLabel, lifeSpan, sexClass } from '../format.js'
import { t } from '../i18n/index.js'

/** Eine Person in den Zweigen eines Nachnamens, darunter je Familie Partner und Kinder (rekursiv) */
const props = defineProps({
  id:   { type: Number, required: true },
  path: { type: Array, required: true },   // Vorfahren in diesem Zweig – schützt vor Schleifen in den Daten
})
const { data, bearers, open, selectedId, edit } = inject('surnameBranches')

const person   = computed(() => data.value.persons[props.id] ?? { id: props.id })
const isBearer = computed(() => bearers.value.has(props.id))
// Nur Träger zeigen ihre Familien; Kinder ohne den Namen sind die letzte Ebene
const families = computed(() => isBearer.value && !props.path.includes(props.id) ? (data.value.families[props.id] ?? []) : [])
const count    = computed(() => families.value.reduce((n, f) => n + f.children.length, 0))
const hasMore  = computed(() => families.value.some(f => f.spouse) || count.value > 0)
const isOpen   = computed(() => open.has(props.id))
const childPath = computed(() => [...props.path, props.id])
const selected = id => selectedId.value != null && String(selectedId.value) === String(id)

function toggle() { isOpen.value ? open.delete(props.id) : open.add(props.id) }
</script>

<template>
<li class="branch-node">
  <div :class="['branch-person', sexClass(person), { 'branch-other': !isBearer, selected: selected(id) }]">
    <button v-if="hasMore" type="button" class="branch-toggle" :aria-expanded="isOpen"
      :aria-label="isOpen ? t('surnames.close') : t('surnames.open')" @click="toggle">
      <i :class="['pi', isOpen ? 'pi-chevron-down' : 'pi-chevron-right']" />
    </button>
    <span v-else class="branch-toggle" aria-hidden="true" />
    <button type="button" class="branch-name" @click="edit(id)">
      <span class="branch-dot" aria-hidden="true" />{{ nameLabel(person) }}
    </button>
    <span v-if="lifeSpan(person)" class="branch-years">{{ lifeSpan(person) }}</span>
    <span v-if="!isOpen && count" class="branch-count" :title="t('surnames.children', { n: count })">{{ count }}</span>
  </div>

  <template v-if="isOpen">
    <template v-for="(f, i) in families" :key="i">
      <div v-if="f.spouse" :class="['branch-spouse', sexClass(data.persons[f.spouse]), { selected: selected(f.spouse) }]">
        <span class="branch-marr" aria-hidden="true">⚭</span>
        <button type="button" class="branch-name" @click="edit(f.spouse)">{{ nameLabel(data.persons[f.spouse]) }}</button>
        <span v-if="lifeSpan(data.persons[f.spouse])" class="branch-years">{{ lifeSpan(data.persons[f.spouse]) }}</span>
      </div>
      <ul v-if="f.children.length" class="branch-tree">
        <SurnameBranchNode v-for="c in f.children" :key="c" :id="c" :path="childPath" />
      </ul>
    </template>
  </template>
</li>
</template>
