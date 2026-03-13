import js from "@eslint/js";
import eslintConfigPrettier from "eslint-config-prettier/flat";
import { importX } from "eslint-plugin-import-x";
import svelte from "eslint-plugin-svelte";
import { defineConfig } from "eslint/config";
import globals from "globals";
import tseslint from "typescript-eslint";
import svelteConfig from "./svelte.config.ts";

export default defineConfig(
	js.configs.recommended,
	...tseslint.configs.strictTypeChecked,
	...tseslint.configs.stylisticTypeChecked,
	importX.flatConfigs.recommended,
	importX.flatConfigs.typescript,
	...svelte.configs.recommended,
	eslintConfigPrettier,
	...svelte.configs.prettier,
	{
		ignores: ["vendor/**", "public/build/**", "public/vendor/**", "resources/js/wayfinder/**"],
	},
	{
		linterOptions: {
			reportUnusedDisableDirectives: "error",
			reportUnusedInlineConfigs: "error",
		},
		languageOptions: {
			globals: { ...globals.browser, ...globals.node },
			parserOptions: {
				projectService: true,
				ecmaFeatures: {
					impliedStrict: true,
				},
			},
		},
		rules: {
			"import-x/no-named-as-default-member": "off", // Namespace imports (e.g. tseslint.configs) are more readable
		},
	},
	{
		files: ["**/*.svelte", "**/*.svelte.ts"],
		languageOptions: {
			parserOptions: {
				projectService: true,
				extraFileExtensions: [".svelte"],
				parser: tseslint.parser, // This is needed for svelte files with lang="ts" to be parsed correctly.
				svelteConfig,
			},
		},
	},
	{
		settings: {
			importX: {
				extensions: [".ts", ".svelte"],
			},
			svelte: {
				ignoreWarnings: [
					// "@typescript-eslint/no-unsafe-assignment",
					// "@typescript-eslint/no-unsafe-member-access",
				],
			},
		},
	},
	{
		files: ["**/*.{ts,mts,cts}"],
		rules: {
			"no-undef": "off", // see: https://typescript-eslint.io/troubleshooting/faqs/eslint/#i-get-errors-from-the-no-undef-rule-about-global-variables-not-being-defined-even-though-there-are-no-typescript-errors
		},
	},
);
