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
			proxy: {
				'/api/v2': {
					target: 'http://localhost:3001/api/v2',
					changeOrigin: true
				}
			},
			optimizeDeps: {
				exclude: ['svelte-kit-cookie-session']
			},
			server: {
				fs: {
					allow: ['./src/lib/locales/']
				}
			}
		}
	}
};

export default config;
