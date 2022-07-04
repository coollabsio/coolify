import adapter from '@sveltejs/adapter-static';
import preprocess from 'svelte-preprocess';

/** @type {import('@sveltejs/kit').Config} */
const config = {
	preprocess: preprocess(),
	kit: {
		vite: {
			server: {
				fs: {
					allow: ['./src/lib/locales/']
				}
			},
		},
		adapter: adapter({
			pages: 'build',
			assets: 'build',
			fallback: 'index.html',
			precompress: true
		}),
		prerender: {
			default: false
		},
	}
};

export default config;
