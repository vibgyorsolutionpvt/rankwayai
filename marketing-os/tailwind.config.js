import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.jsx',
    ],

    theme: {
        extend: {
            colors: {
                ink: {
                    DEFAULT: '#0B1220',
                    soft: '#1A2332',
                    muted: '#5B667A',
                },
                mist: {
                    DEFAULT: '#F3F5F8',
                    deep: '#E6EAF0',
                },
                signal: {
                    DEFAULT: '#0E9F90',
                    soft: '#D7F3EF',
                    strong: '#0B7F73',
                },
                line: '#D5DCE6',
                danger: {
                    DEFAULT: '#C0392B',
                    soft: '#FDECEA',
                },
            },
            fontFamily: {
                sans: ['"Plus Jakarta Sans"', ...defaultTheme.fontFamily.sans],
                display: ['Outfit', ...defaultTheme.fontFamily.sans],
            },
            boxShadow: {
                panel: '0 18px 50px rgba(11, 18, 32, 0.08)',
                lift: '0 12px 28px rgba(14, 159, 144, 0.22)',
            },
            keyframes: {
                'fade-up': {
                    '0%': { opacity: '0', transform: 'translateY(14px)' },
                    '100%': { opacity: '1', transform: 'translateY(0)' },
                },
                'fade-in': {
                    '0%': { opacity: '0' },
                    '100%': { opacity: '1' },
                },
                float: {
                    '0%, 100%': { transform: 'translateY(0)' },
                    '50%': { transform: 'translateY(-8px)' },
                },
                shimmer: {
                    '0%': { backgroundPosition: '0% 50%' },
                    '100%': { backgroundPosition: '100% 50%' },
                },
            },
            animation: {
                'fade-up': 'fade-up 0.55s ease-out both',
                'fade-in': 'fade-in 0.4s ease-out both',
                float: 'float 6s ease-in-out infinite',
                shimmer: 'shimmer 8s ease infinite',
            },
        },
    },

    plugins: [forms],
};
