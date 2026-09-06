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
                'resources/css/buyer/buyer.css',
                'resources/css/buyer/home.css',
                'resources/css/buyer/products.css',
                'resources/css/buyer/cart.css',
                'resources/css/buyer/checkout.css',
                'resources/css/buyer/orders.css',
                'resources/css/buyer/chat.css',
                'resources/css/buyer/account.css',
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
