import preprocess from 'svelte-preprocess';
import adapter from '@sveltejs/adapter-node';

const config = {
	preprocess: preprocess(),
	kit: {
		target: '#svelte',
		adapter: adapter(),
		prerender: {
            enabled: false,
        },
		headers: {
			host: 'X-Forwarded-Host',
		},
		floc: true,
		vite: {
			optimizeDeps: {
				exclude: ['svelte-kit-cookie-session'],
			}
		}
	}
};

export default config;
