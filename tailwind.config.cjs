const defaultTheme = require('tailwindcss/defaultTheme');
const colors = require('tailwindcss/colors');
module.exports = {
	content: ['./**/*.html', './src/**/*.{js,jsx,ts,tsx,svelte}'],
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
				sans: ['Poppins', ...defaultTheme.fontFamily.sans]
			},
			colors: {
				...colors,
				coollabs: '#6B16ED',
				'coollabs-100': '#7317FF',
				coolblack: '#161616',
				'coolgray-100': '#181818',
				'coolgray-200': '#202020',
				'coolgray-300': '#242424',
				'coolgray-400': '#282828',
				'coolgray-500': '#323232'
			}
		}
	},
	variants: {
		extend: {}
	},
	plugins: []
};
