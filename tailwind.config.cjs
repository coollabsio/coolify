const defaultTheme = require('tailwindcss/defaultTheme');
const colors = require('tailwindcss/colors');
const { tailwindExtractor } = require('tailwindcss/lib/lib/purgeUnusedStyles');

const svelteClassColonExtractor = (content) => {
	return content.match(/(?<=class:)([a-zA-Z0-9_-]+)/gm) || [];
};
module.exports = {
	mode: 'jit',
	purge: ['./**/*.html', './src/**/*.{js,jsx,ts,tsx,svelte}'],
	important: true,
	theme: {
		extend: {
			keyframes: {
				wiggle: {
					'0%, 100%': { transform: 'rotate(-3deg)' },
					'50%': { transform: 'rotate(3deg)' }
				}
			},
			animation: {
				wiggle: 'wiggle 0.5s ease-in-out infinite'
			},
			fontFamily: {
				sans: ['Montserrat', ...defaultTheme.fontFamily.sans]
			},
			colors: {
				...colors,
				coolblack: '#161616',
				'coolgray-100': '#181818',
				'coolgray-200': '#202020',
				'coolgray-300': '#242424'
			}
		}
	},
	variants: {
		extend: {
			opacity: ['disabled'],
			animation: ['hover', 'focus']
		}
	},
	plugins: []
};
