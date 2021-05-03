require('dotenv-extended').load();
const preprocess = require('svelte-preprocess');
const path = require('path');

/** @type {import('@sveltejs/kit').Config} */
module.exports = {
	// Consult https://github.com/sveltejs/svelte-preprocess
	// for more information about preprocessors
	preprocess: [
		preprocess({
			postcss: true
		})
	],

	kit: {
		// hydrate the <div id="svelte"> element in src/app.html
		target: '#svelte',
		hostHeader: 'X-Forwarded-Host',
		floc: true,
		vite: {
			server: {
				hmr: {
					port: 23456
				}
			},
			resolve: {
				alias: {
					$store: path.resolve('./src/store/index.ts'),
					$api: path.resolve('./src/routes/api/_index.ts'),
					$models: path.resolve('./src/models/')
				}
			}
		}
	}
};
