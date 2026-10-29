import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import path from 'path'

export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  server: {
    port: 7171,
    strictPort: true, // fail loudly instead of drifting to 7172 — API proxy & CORS assume this port
    proxy: {
      '/api': {
        target: process.env.JARVIS_API_URL ?? 'http://localhost:7170',
        changeOrigin: true,
      },
    },
  },
})
