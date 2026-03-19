import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// Polyfill crypto for Node < 19 or environments where it's missing in Vite
import crypto from 'node:crypto';
if (!globalThis.crypto) {
    // @ts-ignore
    globalThis.crypto = crypto.webcrypto;
}

// https://vitejs.dev/config/
export default defineConfig({
    plugins: [react()],
    server: {
        port: 5174,
        host: true
    }
}) 