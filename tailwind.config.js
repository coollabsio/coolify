/** @type {import('tailwindcss').Config} */
module.exports = {
    mode: "jit",
    content: [
        "./resources/**/*.blade.php",
        "./app/**/*.php",
        "./resources/**/*.js",
        "./resources/**/*.vue",
        "./node_modules/flowbite/**/*.js",
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: ["Inter", "sans-serif"],
            },
            colors: {
                "coolgray-100": "#181818",
                "coolgray-200": "#202020",
                "coolgray-300": "#242424",
                "coolgray-400": "#282828",
                "coolgray-500": "#323232",
                coollabs: "#6B16ED",
                "coollabs-100": "#7317FF",
                warning: "#fcd44f",
                "warning-100": "#ffd54d",

                applications: "#16A34A",
                databases: "#9333EA",
                "databases-100": "#9b46ea",
                destinations: "#0284C7",
                sources: "#EA580C",
                services: "#DB2777",
                settings: "#FEE440",
                iam: "#C026D3",

                coolblack: "#141414",
            },
        },
    },
    variants: {
        scrollbar: ["dark"],
        extend: {},
    },
    // daisyui: {
    //     themes: [
    //         {
    //             coollabs: {
    //                 primary: "#6B16ED",
    //                 secondary: "#4338ca",
    //                 accent: "#4338ca",
    //                 neutral: "#1B1D1D",
    //                 "base-100": "#181818",
    //                 info: "#2563EB",
    //                 success: "#16A34A",
    //                 warning: "#FCD34D",
    //                 error: "#DC2626",
    //             },
    //         },
    //     ],
    // },
    plugins: [
        require("tailwindcss-scrollbar"),
        require("@tailwindcss/typography"),
        // require("daisyui"),
        require("flowbite/plugin"),
    ],
};
