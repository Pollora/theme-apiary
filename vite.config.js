import { defineConfig } from 'vite';
import pollora from '@pollora/vite-config';

export default defineConfig({
    plugins: [
        pollora({
            type: 'theme',
            themeJson: {
                fontLabels: {
                    sans: 'Sans Serif',
                    mono: 'Monospace',
                },
                fontSizeLabels: {
                    xs: 'Extra Small',
                    sm: 'Small',
                    base: 'Medium',
                    lg: 'Large',
                    xl: 'Extra Large',
                    '2xl': '2X Large',
                    '3xl': '3X Large',
                    '4xl': '4X Large',
                    '5xl': '5X Large',
                },
                borderRadiusLabels: {
                    xs: 'Extra Small',
                    sm: 'Small',
                    md: 'Medium',
                    lg: 'Large',
                    xl: 'Extra Large',
                    '2xl': '2X Large',
                },
            },
        }),
    ],
});
