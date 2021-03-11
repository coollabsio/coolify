const { tailwindExtractor } = require('tailwindcss/lib/lib/purgeUnusedStyles')

const svelteClassColonExtractor = (content) => {
  return content.match(/(?<=class:)([a-zA-Z0-9_-]+)/gm) || []
}
const defaultTheme = require('tailwindcss/defaultTheme')
const colors = require('tailwindcss/colors')
module.exports = {
  purge: {
    enabled: process.env.NODE_ENV === 'production',
    content: [
      './src/**/*.svelte',
      './src/**/*.html',
      './src/**/*.css',
      './index.html'
    ],
    preserveHtmlElements: true,
    options: {
      safelist: [/svelte-/],
      defaultExtractor: (content) => {
        // WARNING: tailwindExtractor is internal tailwind api
        // if this breaks after a tailwind update, report to svite repo
        return [
          ...tailwindExtractor(content),
          ...svelteClassColonExtractor(content)
        ]
      },
      keyframes: true
    }
  },
  darkMode: false,
  important: true,
  theme: {
    extend: {
      fontFamily: {
        sans: ['Inter', ...defaultTheme.fontFamily.sans]
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
      opacity: ['disabled']
    }
  },
  plugins: []
}
