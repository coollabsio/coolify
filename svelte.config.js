import { vitePreprocess } from '@sveltejs/vite-plugin-svelte';

export default {
  preprocess: vitePreprocess(),
  compilerOptions: {
    hydratable: true,
  },
  ModuleCompileOptions: {
    dev: true,
  },
};
