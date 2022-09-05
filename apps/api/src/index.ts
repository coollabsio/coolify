import Fastify from 'fastify';
import cors from '@fastify/cors';
import serve from '@fastify/static';
import env from '@fastify/env';
import cookie from '@fastify/cookie';
import path, { join } from 'path';
import autoLoad from '@fastify/autoload';
import { asyncExecShell, createRemoteEngineConfiguration, isDev, listSettings, prisma, version } from './lib/common';
import { scheduler } from './lib/scheduler';
import { compareVersions } from 'compare-versions';
import Graceful from '@ladjs/graceful'
declare module 'fastify' {
	interface FastifyInstance {
		config: {
			COOLIFY_APP_ID: string,
			COOLIFY_SECRET_KEY: string,
			COOLIFY_DATABASE_URL: string,
			COOLIFY_SENTRY_DSN: string,
			COOLIFY_IS_ON: string,
			COOLIFY_WHITE_LABELED: string,
			COOLIFY_WHITE_LABELED_ICON: string | null,
			COOLIFY_AUTO_UPDATE: string,
		};
	}
}

const port = isDev ? 3001 : 3000;
const host = '0.0.0.0';
const fastify = Fastify({
	logger: false,
	trustProxy: true
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
			type: 'string',
			default: 'false'
		},
		COOLIFY_WHITE_LABELED_ICON: {
			type: 'string',
			default: null
		},
		COOLIFY_AUTO_UPDATE: {
			type: 'string',
			default: 'false'
		},

	}
};

const options = {
	schema,
	dotenv: true
};
fastify.register(env, options);
if (!isDev) {
	fastify.register(serve, {
		root: path.join(__dirname, './public'),
		preCompressed: true
	});
	fastify.setNotFoundHandler(async function (request, reply) {
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
	console.log(`Coolify's API is listening on ${host}:${port}`);
	await initServer();

	const graceful = new Graceful({ brees: [scheduler] });
	graceful.listen();

	setInterval(async () => {
		if (!scheduler.workers.has('deployApplication')) {
			scheduler.run('deployApplication');
		}
		if (!scheduler.workers.has('infrastructure')) {
			scheduler.run('infrastructure');
		}
	}, 2000)

	// autoUpdater
	setInterval(async () => {
		scheduler.workers.has('infrastructure') && scheduler.workers.get('infrastructure').postMessage("action:autoUpdater")
	}, isDev ? 5000 : 60000 * 15)

	// cleanupStorage
	setInterval(async () => {
		scheduler.workers.has('infrastructure') && scheduler.workers.get('infrastructure').postMessage("action:cleanupStorage")
	}, isDev ? 6000 : 60000 * 10)

	// checkProxies
	setInterval(async () => {
		scheduler.workers.has('infrastructure') && scheduler.workers.get('infrastructure').postMessage("action:checkProxies")
	}, 10000)

	// cleanupPrismaEngines
	// setInterval(async () => {
	// 	scheduler.workers.has('infrastructure') && scheduler.workers.get('infrastructure').postMessage("action:cleanupPrismaEngines")
	// }, 60000)

	await Promise.all([
		getArch(),
		getIPAddress(),
		// configureRemoteDockers(),
	])
});
async function getIPAddress() {
	console.log('getIPAddress')
	const { publicIpv4, publicIpv6 } = await import('public-ip')
	try {
		const settings = await listSettings();
		if (!settings.ipv4) {
			const ipv4 = await publicIpv4({ timeout: 2000 })
			await prisma.setting.update({ where: { id: settings.id }, data: { ipv4 } })
		}

		if (!settings.ipv6) {
			const ipv6 = await publicIpv6({ timeout: 2000 })
			await prisma.setting.update({ where: { id: settings.id }, data: { ipv6 } })
		}

	} catch (error) { }
}
async function initServer() {
	try {
		await asyncExecShell(`docker network create --attachable coolify`);
	} catch (error) { }
	try {
		const isOlder = compareVersions('3.8.1', version);
		if (isOlder === 1) {
			await prisma.build.updateMany({ where: { status: { in: ['running', 'queued'] } }, data: { status: 'failed' } });
		}
	} catch (error) { }
}
async function getArch() {
	console.log('getArch')
	try {
		const settings = await prisma.setting.findFirst({})
		if (settings && !settings.arch) {
			await prisma.setting.update({ where: { id: settings.id }, data: { arch: process.arch } })
		}
	} catch (error) { }
}



async function configureRemoteDockers() {
	console.log('configureRemoteDockers')
	try {
		const remoteDocker = await prisma.destinationDocker.findMany({
			where: { remoteVerified: true, remoteEngine: true }
		});
		if (remoteDocker.length > 0) {
			for (const docker of remoteDocker) {
				await createRemoteEngineConfiguration(docker.id)
			}
		}
	} catch (error) {
		console.log(error)
	}
}
