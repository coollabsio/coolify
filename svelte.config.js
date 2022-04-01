import preprocess from 'svelte-preprocess';
import adapter from '@sveltejs/adapter-node';

const config = {
	preprocess: preprocess(),
	kit: {
		adapter: adapter(),
		prerender: {
			enabled: false
		},
		floc: true,
		vite: {
			optimizeDeps: {
				exclude: ['svelte-kit-cookie-session']
			},
			server: {
				fs: {
					// Allow serving files from one level up to the project root
					allow: ['../locales']
				}
			}
		}
	}
};

export default config;
