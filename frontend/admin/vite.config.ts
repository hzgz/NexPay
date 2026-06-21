import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

const backendTarget = process.env.VITE_BACKEND_TARGET || 'http://127.0.0.1:5174'

// https://vite.dev/config/
export default defineConfig({
  base: '/admin/',
  plugins: [vue()],
  server: {
    host: '127.0.0.1',
    port: 5185,
    strictPort: true,
    proxy: {
      '/api': {
        target: backendTarget,
        changeOrigin: true,
      },
      '/submit.php': {
        target: backendTarget,
        changeOrigin: true,
      },
      '/mapi.php': {
        target: backendTarget,
        changeOrigin: true,
      },
      '/api.php': {
        target: backendTarget,
        changeOrigin: true,
      },
      '/pay': {
        target: backendTarget,
        changeOrigin: true,
      },
    },
  },
})
