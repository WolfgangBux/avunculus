import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// Build landet in ../public – von dort liefert router.php die App aus.
// Im Dev-Modus (npm run dev) werden /api-Aufrufe an den PHP-Server weitergereicht:
//   php -S localhost:8000 router.php
export default defineConfig({
  plugins: [vue()],
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
