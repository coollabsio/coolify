import { type Config } from "prettier";
import { type PluginOptions } from "prettier-plugin-tailwindcss";

const config: Config & PluginOptions = {
	printWidth: 100, // Matches Rust default and looks the best
	useTabs: true, // Preferred for accessibility (screen readers, variable fonts) and tab width is easily adjustable per user
	plugins: [
		"@ianvs/prettier-plugin-sort-imports",
		"prettier-plugin-svelte",
		"prettier-plugin-tailwindcss",
	],
	tailwindStylesheet: "./resources/css/app.css",
	overrides: [{ files: "*.svelte", options: { parser: "svelte" } }],
	svelteStrictMode: true,
	importOrderTypeScriptVersion: "5.0.0",
	importOrder: [
		"<TYPES>^(node:)",
		"<TYPES>",
		"<TYPES>^[.]",
		"<BUILTIN_MODULES>",
		"<THIRD_PARTY_MODULES>",
		"^#.+",
		"^[.]",
	],
};

export default config;
