import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './app/Enums/*.php',   // classes de selo ficam no enum StatusNota
        './app/Models/Importacao.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['"IBM Plex Sans"', ...defaultTheme.fontFamily.sans],
                mono: ['"IBM Plex Mono"', ...defaultTheme.fontFamily.mono],
            },
            colors: {
                // Verde da marca (contraste AA com branco em 600+)
                brand: { 50: '#F2FAF6', 100: '#E3F1EA', 200: '#BFE2D0', 500: '#1B8A66', 600: '#0B6B4D', 700: '#095A41', 800: '#0A5A40', 900: '#063B2B' },
                ok:    { 50: '#E9F6EF', 100: '#DDF3E8', 800: '#0A5A40' },
                warn:  { 50: '#FFF6EC', 100: '#FDE9D7', 200: '#F0C9A3', 600: '#D9621A', 800: '#8A3B00', 900: '#5C2A00' },
                info:  { 100: '#E4EBFB', 500: '#7C9BE0', 800: '#1D3F9E' },
                ink:   { 900: '#16201B', 700: '#3E4A43', 600: '#4D5A52' },
                chao:  '#F4F6F3',
                linha: '#D9DFD9',
            },
        },
    },

    plugins: [forms],
};
