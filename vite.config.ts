import { svelte } from "@sveltejs/vite-plugin-svelte";
import tailwindcss from "@tailwindcss/vite";
import laravel from "laravel-vite-plugin";
import { defineConfig } from "vite";

export default defineConfig({
	plugins: [
		laravel({
			input: ["resources/js/app.ts"],
			refresh: true,
		}),
		svelte({
			configFile: "svelte.config.ts",
			// TODO: Remove once Inertia v3 https://github.com/inertiajs/inertia/tree/3.x is released and runes=true is set in svelte.config.ts
			dynamicCompileOptions({ filename, compileOptions }) {
				// Enforce runes mode for all files not in node_modules
				if (!/[\\/]node_modules[\\/]/.test(filename) && !compileOptions.runes) {
					return { runes: true };
				}
				return undefined;
			},
		}),
		tailwindcss(),
	],
	server: {
		watch: {
			ignored: ["**/storage/framework/views/**"],
		},
	},
});
