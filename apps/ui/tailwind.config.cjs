const defaultTheme = require('tailwindcss/defaultTheme');

module.exports = {
	content: ['./**/*.html', './src/**/*.{js,jsx,ts,tsx,svelte}', "./node_modules/flowbite-svelte/**/*.{html,js,svelte,ts}",],
	important: true,
	daisyui: {
		themes: [
			{
				coollabs: {
					"base-100": "#323232",
					"base-200": "#242424",
					"base-300": "#181818",
					"primary": "#6B16ED",
					"primary-content": "#fff",
					"secondary": "#343232",
					"accent": "#343232",
					"neutral": "#272626",
					"info": "#0284c7",
					"success": "#16A34A",
					"warning": "#FFFF00",
					"error": "#DC2626",
					"--rounded-btn": "0.3rem",
					"--btn-text-case": "normal"
				},
			}
		],
	},
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
				sans: ['Poppins', ...defaultTheme.fontFamily.sans]
			},
			colors: {
				"applications": "#16A34A",
				"databases": "#9333EA",
				"databases-100": "#9b46ea",
				"destinations": "#0284C7",
				"sources": "#EA580C",
				"services": "#DB2777",
				"settings": "#FEE440",
				"iam": "#C026D3",
				coollabs: '#6B16ED',
				'coollabs-100': '#7317FF',
				coolblack: '#141414',
				'coolgray-100': '#181818',
				'coolgray-200': '#202020',
				'coolgray-300': '#242424',
				'coolgray-400': '#282828',
				'coolgray-500': '#323232'
			}
		}
	},
	variants: {
		scrollbar: ['dark'],
		extend: {}
	},
	darkMode: 'class',
	plugins: [require('tailwindcss-scrollbar'), require('daisyui'), require("@tailwindcss/typography")]
};
