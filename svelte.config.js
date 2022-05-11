import preprocess from 'svelte-preprocess';
import adapter from '@sveltejs/adapter-node';

import { Server } from 'socket.io';
const webSocketServer = {
	name: 'webSocketServer',
	configureServer(server) {
		const io = new Server(server.httpServer);
		io.on('connection', (socket) => {
			socket.emit('eventFromServer', 'Hello, World 👋');
		});
	}
};

const config = {
	preprocess: preprocess(),
	kit: {
		adapter: adapter(),
		prerender: {
			enabled: false
		},
		floc: true,
		vite: {
			plugins: [webSocketServer],
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
