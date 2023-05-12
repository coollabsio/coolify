/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    "./resources/**/*.blade.php",
    "./resources/**/*.js",
    "./resources/**/*.vue",
  ],
  theme: {
    extend: {
        fontFamily: {
            sans: ['Inter', 'sans-serif'],
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
            'coollabs': '#6B16ED',
            'coollabs-100': '#7317FF',
            'coolblack': '#141414',
            'coolgray-100': '#181818',
            'coolgray-200': '#202020',
            'coolgray-300': '#242424',
            'coolgray-400': '#282828',
            'coolgray-500': '#323232'
        }
    },
  },
  plugins: [],
}
