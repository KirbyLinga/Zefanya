import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        tailwindcss(),
        laravel({
            input: [
                'resources/css/app.css',
                'resources/css/design-system.css',
                'resources/css/login-modal.css',
                'resources/css/auth-buttons.css',
                'resources/css/admin/admin.css',
                'resources/css/auth.css',
                'resources/css/buyer/buyer.css',
                'resources/css/buyer/home.css',
                'resources/css/buyer/products.css',
                'resources/css/buyer/cart.css',
                'resources/css/buyer/checkout.css',
                'resources/css/buyer/orders.css',
                'resources/css/buyer/chat.css',
                'resources/css/buyer/account.css',
                'resources/css/buyer/buyer-register-modal.css',
                'resources/css/seller/seller-app.css',
                'resources/css/seller/seller-products.css',
                'resources/css/seller/seller-dashboard.css',
                'resources/js/buyer/buyer.js',
                'resources/js/buyer/home.js',
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
