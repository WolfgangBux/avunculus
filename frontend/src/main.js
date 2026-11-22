import { createApp } from 'vue'
import PrimeVue from 'openvue/config'
import ToastService from 'openvue/toastservice'
import ConfirmationService from 'openvue/confirmationservice'
import Tooltip from 'openvue/tooltip'
import { definePreset } from '@openvue/themes'
import Aura from '@openvue/themes/aura'

import 'primeicons/primeicons.css'
import './assets/main.css'

import App from './App.vue'
import { parseReportHash } from './composables/reports.js'

// Aura-Theme mit dem Avunculus-Orange als Primärfarbe
const AvunculusPreset = definePreset(Aura, {
  semantic: {
    primary: {
      50:  '#fef5ee',
      100: '#fde8d7',
      200: '#fbcdae',
      300: '#f7a97a',
      400: '#f17d45',
      500: '#e8641b',
      600: '#c9520f',
      700: '#a6400f',
      800: '#853514',
      900: '#6c2e14',
      950: '#3a1508',
    },
    colorScheme: {
      light: {
        // Platzhalter deutlich heller als echte Eingaben
        formField: { placeholderColor: '{surface.400}' },
      },
    },
  },
})

// #/report/… ist die Druckansicht eines Berichts (eigener Tab, ohne App-Rahmen) – erst bei Bedarf geladen
const report = parseReportHash()
const root   = report ? import('./components/reports/ReportPage.vue').then(m => m.default) : Promise.resolve(App)
if (report) document.body.classList.add('report-body')

root.then(component => createApp(component)
  .use(PrimeVue, {
    theme: {
      preset: AvunculusPreset,
      options: { darkModeSelector: false },
    },
  })
  .use(ToastService)
  .use(ConfirmationService)
  .directive('tooltip', Tooltip)
  .mount('#app'))
