import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'
import path from 'path'

// The Vue tests: components and chart logic in jsdom, ECharts mocked.
export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: { '@': path.resolve(import.meta.dirname, 'resources/js') },
  },
  test: {
    environment: 'jsdom',
    include: ['resources/js/**/*.test.js'],
  },
})
