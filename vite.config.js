import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/design-system.css',
                'resources/css/landing.css',
                'resources/css/login-modal.css',
                'resources/css/admin/admin.css',
                'resources/css/auth.css',
            ],
            refresh: true,
        }),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
