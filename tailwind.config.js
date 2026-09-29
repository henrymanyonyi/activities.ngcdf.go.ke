import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import typography from '@tailwindcss/typography';

/**
 * Mirrors smart.ngcdf.go.ke/tailwind.config.js (PAKA-RANGI). Keep the type
 * scale identical to Smart's so the two apps look the same side by side.
 *
 * @type {import('tailwindcss').Config}
 */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './vendor/laravel/jetstream/**/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        // Badge classes live on enums/models (PAKA-RANGI §3.4), so PHP is scanned too.
        './app/**/*.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', 'Poppins', ...defaultTheme.fontFamily.sans],
            },
            fontSize: {
                xs: ['13px', { lineHeight: '16px' }],
                sm: ['15px', { lineHeight: '20px' }],
                base: ['17px', { lineHeight: '24px' }],
                lg: ['20px', { lineHeight: '28px' }],
                xl: ['22px', { lineHeight: '32px' }],
                '2xl': ['26px', { lineHeight: '36px' }],
                '3xl': ['32px', { lineHeight: '40px' }],
            },
        },
    },

    plugins: [forms, typography],
};
