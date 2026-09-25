import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        tailwindcss(),
        laravel({
            input: [
                'resources/css/app.css',
                'resources/css/login-modal.css',
                'resources/css/auth.css',
                'resources/css/buyer/buyer-register-modal.css',
                'resources/css/seller/products-index.css',
                'resources/js/buyer/buyer.js',
                'resources/js/buyer/buyer-register-modal.js',
                'resources/js/seller/seller-register-modal.js',
                'resources/js/logistics/logistics-register-modal.js',
                'resources/js/seller/product-create-modal.js',
                'resources/js/seller/products-index.js',
                'resources/js/seller/theme-toggle.js',
                'resources/js/seller/notification-bell.js',
                'resources/js/seller/sidebar-tooltips.js',
                'resources/js/logistics/sidebar-tooltips.js',
                'resources/js/buyer/home.js',
                'resources/js/buyer/product-show.js',
                'resources/js/buyer/cart.js',
            ],
            refresh: true,
        }),
    ],
    server: {
        host: '127.0.0.1',
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
