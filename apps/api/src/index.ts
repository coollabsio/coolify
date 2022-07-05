import Fastify from 'fastify';
import cors from '@fastify/cors';
import serve from '@fastify/static';
import env from '@fastify/env';
import cookie from '@fastify/cookie';
import path, { join } from 'path';
import autoLoad from '@fastify/autoload';
import { asyncExecShell, isDev } from './lib/common';
import { scheduler } from './lib/scheduler';

declare module 'fastify' {
	interface FastifyInstance {
		config: {
			COOLIFY_APP_ID: string,
			COOLIFY_SECRET_KEY: string,
			COOLIFY_DATABASE_URL: string,
			COOLIFY_SENTRY_DSN: string,
			COOLIFY_IS_ON: string,
			COOLIFY_WHITE_LABELED: boolean,
			COOLIFY_WHITE_LABELED_ICON: string | null,
			COOLIFY_AUTO_UPDATE: boolean,
		};
	}
}

const port = isDev ? 3001 : 3000;
const host = '0.0.0.0';
const fastify = Fastify({
	logger: false
});
const schema = {
	type: 'object',
	required: ['COOLIFY_SECRET_KEY', 'COOLIFY_DATABASE_URL', 'COOLIFY_IS_ON'],
	properties: {
		COOLIFY_APP_ID: {
			type: 'string',
		},
		COOLIFY_SECRET_KEY: {
			type: 'string',
		},
		COOLIFY_DATABASE_URL: {
			type: 'string',
			default: 'file:../db/dev.db'
		},
		COOLIFY_SENTRY_DSN: {
			type: 'string',
			default: null
		},
		COOLIFY_IS_ON: {
			type: 'string',
			default: 'docker'
		},
		COOLIFY_WHITE_LABELED: {
			type: 'boolean',
			default: false
		},
		COOLIFY_WHITE_LABELED_ICON: {
			type: 'string',
			default: null
		},
		COOLIFY_AUTO_UPDATE: {
			type: 'boolean',
			default: false
		},

	}
};

const options = {
	schema
};
fastify.register(env, options);
if (!isDev) {
	fastify.register(serve, {
		root: path.join(__dirname, './public'),
		preCompressed: true
	});
	fastify.setNotFoundHandler(function (request, reply) {
		if (request.raw.url && request.raw.url.startsWith('/api')) {
			return reply.status(404).send({
				success: false
			});
		}
		return reply.status(200).sendFile('index.html');
	});
}
fastify.register(autoLoad, {
	dir: join(__dirname, 'plugins')
});
fastify.register(autoLoad, {
	dir: join(__dirname, 'routes')
});

fastify.register(cookie)
fastify.register(cors);
fastify.listen({ port, host }, async (err: any, address: any) => {
	if (err) {
		console.error(err);
		process.exit(1);
	}
	await initServer()
	await scheduler.start('deployApplication');
	await scheduler.start('cleanupStorage');
	await scheduler.start('checkProxies')

	// Check if no build is running, try to autoupdate.
	setInterval(() => {
		if (scheduler.workers.has('deployApplication')) {
			scheduler.workers.get('deployApplication').postMessage("status");
		}
	}, 60000 * 10)

	scheduler.on('worker deleted', async (name) => {
		if (name === 'autoUpdater') {
			await scheduler.start('deployApplication');
		}

	});

});

async function initServer() {
	try {
		await asyncExecShell(`docker network create --attachable coolify`);
	} catch (error) { }
}


