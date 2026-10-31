import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
// base './' + hash routing: the build runs from any folder on shared hosting with no rewrite rules.
export default defineConfig({ plugins: [react()], base: './', build: { outDir: 'dist', emptyOutDir: true, sourcemap: false },
  server: { proxy: { '/api': 'http://127.0.0.1:8080' } } });
