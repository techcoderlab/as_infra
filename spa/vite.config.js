import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
// import vueDevTools from 'vite-plugin-vue-devtools'
import AutoImport from 'unplugin-auto-import/vite'

// https://vite.dev/config/
export default defineConfig({
  server: {
    proxy: {
      '/api': {
        // target: 'http://127.0.0.1',
        target: 'https://renunciatory-unacerbic-ricky.ngrok-free.dev',
        changeOrigin: true,
        headers: {
          'ngrok-skip-browser-warning': 'true',
        },
      },
    },
  },
  plugins: [
    vue(),
    // vueDevTools()
    AutoImport({
      dirs: ['./src/utils'], // This tells Vite to auto-import everything from this folder
      dts: true, // Generates TypeScript definitions so your IDE knows they exist
    }),
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
})
