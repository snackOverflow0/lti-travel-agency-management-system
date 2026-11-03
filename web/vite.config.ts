import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
// Version of the unmodified Revision 27 script = its content hash, so browsers fetch it again only when it actually changes.
const protoVersion = createHash('sha256').update(readFileSync('public/prototype/lti-prototype.js')).digest('hex').slice(0, 12);
// base './' + hash routing: the build runs from any folder on shared hosting with no rewrite rules.
export default defineConfig({ plugins: [react()], base: './', define: { __LTI_PROTO_VERSION__: JSON.stringify(protoVersion) }, build: { outDir: 'dist', emptyOutDir: true, sourcemap: false },
  server: { proxy: { '/api': 'http://127.0.0.1:8000' } } });
