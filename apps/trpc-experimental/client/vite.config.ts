import { sveltekit } from '@sveltejs/kit/vite';
import type { UserConfig } from 'vite';

const config: UserConfig = {
	server: {
		host: '0.0.0.0'
	},
	plugins: [sveltekit()],
	define: {
		GITPOD_WORKSPACE_URL: JSON.stringify(process.env.GITPOD_WORKSPACE_URL),
		CODESANDBOX_HOST: JSON.stringify(process.env.CODESANDBOX_HOST)
	}
};

export default config;
