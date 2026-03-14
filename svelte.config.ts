import type { SvelteConfig } from "@sveltejs/vite-plugin-svelte";
import { vitePreprocess } from "@sveltejs/vite-plugin-svelte";

const config: SvelteConfig = {
	preprocess: vitePreprocess(),
	compilerOptions: {
		runes: true,
		discloseVersion: false,
		modernAst: true,
		dev: true,
		experimental: {
			async: true,
		},
	},
	vitePlugin: {
		inspector: {
			showToggleButton: "always",
			toggleButtonPos: "top-right",
		},
	},
};

export default config;
