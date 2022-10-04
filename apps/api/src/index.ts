import Fastify from 'fastify';
import cors from '@fastify/cors';
import serve from '@fastify/static';
import env from '@fastify/env';
import cookie from '@fastify/cookie';
import multipart from '@fastify/multipart';
import path, { join } from 'path';
import autoLoad from '@fastify/autoload';
import { asyncExecShell, createRemoteEngineConfiguration, getDomain, isDev, listSettings, prisma, version } from './lib/common';
import { scheduler } from './lib/scheduler';
import { compareVersions } from 'compare-versions';
import Graceful from '@ladjs/graceful'
import { verifyRemoteDockerEngineFn } from './routes/api/v1/destinations/handlers';
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
(async () => {
	// const settings = prisma.setting.findFirst()
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
	fastify.register(multipart, { limits: { fileSize: 100000 } });
	fastify.register(autoLoad, {
		dir: join(__dirname, 'plugins')
	});
	fastify.register(autoLoad, {
		dir: join(__dirname, 'routes')
	});
	fastify.register(cookie)
	fastify.register(cors);
	// fastify.addHook('onRequest', async (request, reply) => {
	// 	let allowedList = ['coolify:3000'];
	// 	const { ipv4, ipv6, fqdn } = await prisma.setting.findFirst({})

	// 	ipv4 && allowedList.push(`${ipv4}:3000`);
	// 	ipv6 && allowedList.push(ipv6);
	// 	fqdn && allowedList.push(getDomain(fqdn));
	// 	isDev && allowedList.push('localhost:3000') && allowedList.push('localhost:3001') && allowedList.push('host.docker.internal:3001');
	// 	const remotes = await prisma.destinationDocker.findMany({ where: { remoteEngine: true, remoteVerified: true } })
	// 	if (remotes.length > 0) {
	// 		remotes.forEach(remote => {
	// 			allowedList.push(`${remote.remoteIpAddress}:3000`);
	// 		})
	// 	}
	// 	if (!allowedList.includes(request.headers.host)) {
	// 		// console.log('not allowed', request.headers.host)
	// 	}
	// })
	try {
		await fastify.listen({ port, host })
		console.log(`Coolify's API is listening on ${host}:${port}`);
		await initServer();

		// const graceful = new Graceful({ brees: [scheduler] });
		// graceful.listen();

		// setInterval(async () => {
		// 	if (!scheduler.workers.has('deployApplication')) {
		// 		scheduler.run('deployApplication');
		// 	}
		// 	if (!scheduler.workers.has('infrastructure')) {
		// 		scheduler.run('infrastructure');
		// 	}
		// }, 2000)

		// autoUpdater
		// setInterval(async () => {
		// 	scheduler.workers.has('infrastructure') && scheduler.workers.get('infrastructure').postMessage("action:autoUpdater")
		// }, 60000 * 15)

		// // cleanupStorage
		// setInterval(async () => {
		// 	scheduler.workers.has('infrastructure') && scheduler.workers.get('infrastructure').postMessage("action:cleanupStorage")
		// }, 60000 * 10)

		// // checkProxies and checkFluentBit
		// setInterval(async () => {
		// 	scheduler.workers.has('infrastructure') && scheduler.workers.get('infrastructure').postMessage("action:checkProxies")
		// 	scheduler.workers.has('infrastructure') && scheduler.workers.get('infrastructure').postMessage("action:checkFluentBit")
		// }, 10000)

		// setInterval(async () => {
		// 	scheduler.workers.has('infrastructure') && scheduler.workers.get('infrastructure').postMessage("action:copySSLCertificates")
		// }, 2000)

		// await Promise.all([
			// getArch(),
			// getIPAddress(),
			// configureRemoteDockers(),
		// ])
	} catch (error) {
		console.error(error);
		process.exit(1);
	}



})();


async function getIPAddress() {
	const { publicIpv4, publicIpv6 } = await import('public-ip')
	try {
		const settings = await listSettings();
		if (!settings.ipv4) {
			console.log(`Getting public IPv4 address...`);
			const ipv4 = await publicIpv4({ timeout: 2000 })
			// await prisma.setting.update({ where: { id: settings.id }, data: { ipv4 } })
		}

		if (!settings.ipv6) {
			console.log(`Getting public IPv6 address...`);
			const ipv6 = await publicIpv6({ timeout: 2000 })
			// await prisma.setting.update({ where: { id: settings.id }, data: { ipv6 } })
		}

	} catch (error) { }
}
async function initServer() {
	try {
		console.log(`Initializing server...`);
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
	try {
		const settings = await prisma.setting.findFirst({})
		if (settings && !settings.arch) {
			console.log(`Getting architecture...`);
			await prisma.setting.update({ where: { id: settings.id }, data: { arch: process.arch } })
		}
	} catch (error) { }
}

async function configureRemoteDockers() {
	try {
		const remoteDocker = await prisma.destinationDocker.findMany({
			where: { remoteVerified: true, remoteEngine: true }
		});
		if (remoteDocker.length > 0) {
			console.log(`Verifying Remote Docker Engines...`);
			for (const docker of remoteDocker) {
				console.log('Verifying:', docker.remoteIpAddress)
				await verifyRemoteDockerEngineFn(docker.id);
			}
		}
	} catch (error) {
		console.log(error)
	}
}
