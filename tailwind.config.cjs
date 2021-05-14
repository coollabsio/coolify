const defaultTheme = require('tailwindcss/defaultTheme');
const colors = require('tailwindcss/colors');
const { tailwindExtractor } = require('tailwindcss/lib/lib/purgeUnusedStyles');

const svelteClassColonExtractor = (content) => {
	return content.match(/(?<=class:)([a-zA-Z0-9_-]+)/gm) || [];
};
module.exports = {
	mode: 'jit',
	purge: ['./**/*.html', './src/**/*.{js,jsx,ts,tsx,svelte}'],
	// purge: {
	// 	enabled: process.env.NODE_ENV === 'production',
	// 	content: ['./src/**/*.svelte', './src/**/*.html', './src/**/*.css', './index.html'],
	// 	preserveHtmlElements: true,
	// 	options: {
	// 		safelist: [
	// 			/svelte-/,
	// 			'border-green-500',
	// 			'border-yellow-300',
	// 			'border-red-500',
	// 			'hover:border-green-500',
	// 			'hover:border-red-200',
	// 			'hover:bg-red-200',
	// 			'hover:bg-warmGray-900',
	// 			'hover:bg-transparent'
	// 		],
	// 		defaultExtractor: (content) => {
	// 			// WARNING: tailwindExtractor is internal tailwind api
	// 			// if this breaks after a tailwind update, report to svite repo
	// 			return [...tailwindExtractor(content), ...svelteClassColonExtractor(content)];
	// 		},
	// 		keyframes: false
	// 	}
	// },
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
