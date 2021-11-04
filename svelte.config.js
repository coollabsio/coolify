import preprocess from 'svelte-preprocess';
import adapter from '@sveltejs/adapter-node';

const config = {
	preprocess: preprocess(),
	kit: {
		target: '#svelte',
		adapter: adapter(),
		hostHeader: 'X-Forwarded-Host',
		floc: true,
		vite: {
			optimizeDeps: {
				exclude: ['svelte-kit-cookie-session'],
			},
			server: {
				watch: {
					usePolling: true
				},
				hmr: {
					host: 'localhost',
					port: 3000,
					protocol: 'ws'
				}
			}
		}
	}
};

export default config;
