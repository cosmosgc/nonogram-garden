import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig(({ mode }) => ({
    plugins: [
        ...(mode === 'test' ? [] : [laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/app.ts', 'resources/js/audio.ts'],
            refresh: true,
        })]),
        tailwindcss(),
        vue(),
    ],
}));
