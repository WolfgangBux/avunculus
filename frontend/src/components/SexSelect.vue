<script setup>
import { useId } from 'vue'
import { SEX_SYMBOLS } from '../personModel.js'
import { t } from '../i18n/index.js'

/**
 * Geschlecht als vier Symbole (♂ ♀ ⚧ ?), die gewählte Option in der Farbe des Geschlechts.
 * Eigene Optionsfelder statt SelectButton: braucht keine Regeln auf Interna der UI-Bibliothek,
 * Tastatur (Pfeiltasten) und Screenreader funktionieren wie bei jeder Radio-Gruppe.
 */
const model = defineModel({ type: String, default: '' })
defineProps({ invalid: { type: Boolean, default: false } })

const name = useId()
const VALUES = ['M', 'F', 'X', 'U']
</script>

<template>
  <div role="radiogroup" :aria-label="t('sex.label')" :aria-invalid="invalid || undefined"
    :class="['sex-select', { invalid }]">
    <label v-for="v in VALUES" :key="v" :class="['sex-option', 'sex-' + v, { checked: model === v }]"
      v-tooltip.bottom="t('sex.' + v)">
      <input v-model="model" type="radio" :name="name" :value="v" class="sr-only" />
      <span class="sex-symbol" aria-hidden="true">{{ SEX_SYMBOLS[v] }}</span>
      <span class="sr-only">{{ t('sex.' + v) }}</span>
    </label>
  </div>
</template>

<style scoped>
.sex-select {
  display: inline-flex; align-self: flex-start; flex-wrap: wrap; gap: 2px; padding: 2px;
  border-radius: var(--radius-sm); background: var(--color-surface-alt);
}
.sex-select.invalid { box-shadow: 0 0 0 1px var(--color-error); }
.sex-option {
  display: inline-flex; align-items: center; justify-content: center; min-width: 2.4rem;
  padding: .3rem .65rem; border-radius: calc(var(--radius-sm) - 2px); cursor: pointer;
}
.sex-option:hover { background: var(--color-surface); }
.sex-option.checked { background: var(--sex-bg); box-shadow: 0 1px 2px rgba(0, 0, 0, .08); }
.sex-option:has(input:focus-visible) { box-shadow: var(--focus-ring); }
.sex-symbol {
  font-size: 1.45rem; line-height: 1; font-weight: 600;
  font-family: 'Segoe UI Symbol', 'Noto Sans Symbols', 'DejaVu Sans', system-ui, sans-serif;
  color: var(--sex-fg, var(--sex-u-fg));
}
.sex-U .sex-symbol { font-size: 1.15rem; }
</style>
