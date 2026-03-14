import inertia from "@inertiajs/vite";
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
		inertia(),
		svelte({
			configFile: "svelte.config.ts",
		}),
		tailwindcss(),
	],
	server: {
		watch: {
			ignored: ["**/storage/framework/views/**"],
		},
	},
});
