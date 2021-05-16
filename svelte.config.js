import dotEnvExtended from 'dotenv-extended';
dotEnvExtended.load();
import preprocess from 'svelte-preprocess';
import path from 'path';
import adapter from '@sveltejs/adapter-node';
/** @type {import('@sveltejs/kit').Config} */
export default {
	preprocess: [
		preprocess({
			postcss: true
		})
	],
	kit: {
		adapter: adapter({
			out: 'build'
		}),
		target: '#svelte',
		hostHeader: 'X-Forwarded-Host',
		floc: true,
		prerender: {
			enabled: false
		},
		vite: {
			server: {
				hmr: {
					port: 23456
				}
			},
			resolve: {
				alias: {
					$components: path.resolve('./src/components/'),
					$store: path.resolve('./src/store/index.ts'),
					$api: path.resolve('./src/routes/api/'),
					$models: path.resolve('./src/models/')
				}
			}
		}
	}
};
