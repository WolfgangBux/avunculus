import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { readFileSync } from 'node:fs'

// Build landet in ../public – von dort liefert router.php die App aus.
// Im Dev-Modus (npm run dev) werden /api-Aufrufe an den PHP-Server weitergereicht:
//   php -S localhost:8000 router.php
const version = readFileSync(new URL('../VERSION', import.meta.url), 'utf8').trim()

export default defineConfig({
  plugins: [
    vue(),
    // <meta name="generator" content="Avunculus %APP_VERSION%"> in index.html – öffentliche Installationen erkennbar
    { name: 'app-version', transformIndexHtml: html => html.replaceAll('%APP_VERSION%', version) },
  ],
  define: {
    // Versionsnummer für die Fußzeile (src/about.js)
    __APP_VERSION__: JSON.stringify(version),
  },
  base: './',   // relative Pfade: läuft im Hauptordner einer Domain wie in einem Unterordner
  server: {
    proxy: {
      '/api': 'http://localhost:8000',
    },
  },
  build: {
    outDir: '../public',
    emptyOutDir: true,
  },
})
