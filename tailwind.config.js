/** @type {import('tailwindcss').Config} */
const colors = {
    "base": "#101010",
    "warning": "#FCD452",
    "success": "#16A34A",
    "error": "#DC2626",
    "coollabs": "#6B16ED",
    "coollabs-100": "#7317FF",
    "coolgray-100": "#181818",
    "coolgray-200": "#202020",
    "coolgray-300": "#242424",
    "coolgray-400": "#282828",
    "coolgray-500": "#323232",
}

export default {
    darkMode: "selector",
    content: [
        './storage/framework/views/*.php',
        "./resources/**/*.blade.php",
        "./app/**/*.php",
        "./resources/**/*.js",
        "./resources/**/*.vue",
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: ["Inter", "sans-serif"],
            },
            colors
        },
    },
    plugins: [
        require("tailwind-scrollbar"),
        require("@tailwindcss/typography"),
        require("@tailwindcss/forms")
    ],
};
