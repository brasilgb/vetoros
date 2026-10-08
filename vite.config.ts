import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { defineConfig } from 'vite';

// Versão exibida no sistema: fonte única em package.json ("version").
const appVersion = JSON.parse(readFileSync(resolve(__dirname, 'package.json'), 'utf-8')).version as string;

export default defineConfig({
    define: {
        __APP_VERSION__: JSON.stringify(`v${appVersion}`),
    },
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            ssr: 'resources/js/ssr.tsx',
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
    build: {
        rollupOptions: {
            output: {
                manualChunks(id) {
                    if (id.includes('node_modules')) {
                        if (id.includes('@inertiajs') || id.includes('react')) {
                            return 'vendor';
                        }
                    }
                }
            },
        },
    },
    server: {

        cors: true
    },
    esbuild: {
        jsx: 'automatic',
    },
    resolve: {
        alias: {
            'ziggy-js': resolve(__dirname, 'vendor/tightenco/ziggy'),
        },
    },
});
