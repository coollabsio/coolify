import Fastify from 'fastify';
import cors from '@fastify/cors';
import serve from '@fastify/static';
import env from '@fastify/env';
import cookie from '@fastify/cookie';
import multipart from '@fastify/multipart';
import path, { join } from 'path';
import autoLoad from '@fastify/autoload';
import socketIO from 'fastify-socket.io';
import socketIOServer from './realtime';

import {
	cleanupDockerStorage,
	createRemoteEngineConfiguration,
	decrypt,
	executeCommand,
	generateDatabaseConfiguration,
	isDev,
	listSettings,
	prisma,
	sentryDSN,
	startTraefikProxy,
	startTraefikTCPProxy,
	version
} from './lib/common';
import { scheduler } from './lib/scheduler';
import { compareVersions } from 'compare-versions';
import Graceful from '@ladjs/graceful';
import yaml from 'js-yaml';
import fs from 'fs/promises';
import { verifyRemoteDockerEngineFn } from './routes/api/v1/destinations/handlers';
import { checkContainer } from './lib/docker';
import { migrateApplicationPersistentStorage, migrateServicesToNewTemplate } from './lib';
import { refreshTags, refreshTemplates } from './routes/api/v1/handlers';
import * as Sentry from '@sentry/node';
declare module 'fastify' {
	interface FastifyInstance {
		config: {
			COOLIFY_APP_ID: string;
			COOLIFY_SECRET_KEY: string;
			COOLIFY_DATABASE_URL: string;
			COOLIFY_IS_ON: string;
			COOLIFY_WHITE_LABELED: string;
			COOLIFY_WHITE_LABELED_ICON: string | null;
			COOLIFY_AUTO_UPDATE: string;
		};
	}
}

const port = isDev ? 3001 : 3000;
const host = '0.0.0.0';

(async () => {
	const settings = await prisma.setting.findFirst();
	const fastify = Fastify({
		logger: settings?.isAPIDebuggingEnabled || false,
		trustProxy: true
	});

	const schema = {
		type: 'object',
		required: ['COOLIFY_SECRET_KEY', 'COOLIFY_DATABASE_URL', 'COOLIFY_IS_ON'],
		properties: {
			COOLIFY_APP_ID: {
				type: 'string'
			},
			COOLIFY_SECRET_KEY: {
				type: 'string'
			},
			COOLIFY_DATABASE_URL: {
				type: 'string',
				default: 'file:../db/dev.db'
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
			}
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
	fastify.register(cookie);
	fastify.register(cors);
	fastify.register(socketIO, {
		cors: {
			origin: isDev ? '*' : ''
		}
	});
	// To detect allowed origins
	// fastify.addHook('onRequest', async (request, reply) => {
	// 	console.log(request.headers.host)
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
		await fastify.listen({ port, host });
		await socketIOServer(fastify);
		console.log(`Coolify's API is listening on ${host}:${port}`);

		migrateServicesToNewTemplate();
		await migrateApplicationPersistentStorage();
		await initServer();

		const graceful = new Graceful({ brees: [scheduler] });
		graceful.listen();

		setInterval(async () => {
			if (!scheduler.workers.has('deployApplication')) {
				scheduler.run('deployApplication');
			}
		}, 2000);

		// autoUpdater
		setInterval(async () => {
			await autoUpdater();
		}, 60000 * 15);

		// cleanupStorage
		setInterval(async () => {
			await cleanupStorage();
		}, 60000 * 15);

		// checkProxies, checkFluentBit & refresh templates
		setInterval(async () => {
			await checkProxies();
			await checkFluentBit();
		}, 60000);

		// Refresh and check templates
		setInterval(async () => {
			await refreshTemplates();
		}, 60000);

		setInterval(async () => {
			await refreshTags();
		}, 60000);

		setInterval(
			async () => {
				await migrateServicesToNewTemplate();
			},
			isDev ? 10000 : 60000
		);

		setInterval(async () => {
			await copySSLCertificates();
		}, 10000);

		await Promise.all([getTagsTemplates(), getArch(), getIPAddress(), configureRemoteDockers()]);
	} catch (error) {
		console.error(error);
		process.exit(1);
	}
})();

async function getIPAddress() {
	const { publicIpv4, publicIpv6 } = await import('public-ip');
	try {
		const settings = await listSettings();
		if (!settings.ipv4) {
			const ipv4 = await publicIpv4({ timeout: 2000 });
			console.log(`Getting public IPv4 address...`);
			await prisma.setting.update({ where: { id: settings.id }, data: { ipv4 } });
		}

		if (!settings.ipv6) {
			const ipv6 = await publicIpv6({ timeout: 2000 });
			console.log(`Getting public IPv6 address...`);
			await prisma.setting.update({ where: { id: settings.id }, data: { ipv6 } });
		}
	} catch (error) {}
}
async function getTagsTemplates() {
	const { default: got } = await import('got');
	try {
		if (isDev) {
			let templates = await fs.readFile('./devTemplates.yaml', 'utf8');
			let tags = await fs.readFile('./devTags.json', 'utf8');
			try {
				if (await fs.stat('./testTemplate.yaml')) {
					templates = templates + (await fs.readFile('./testTemplate.yaml', 'utf8'));
				}
			} catch (error) {}
			try {
				if (await fs.stat('./testTags.json')) {
					const testTags = await fs.readFile('./testTags.json', 'utf8');
					if (testTags.length > 0) {
						tags = JSON.stringify(JSON.parse(tags).concat(JSON.parse(testTags)));
					}
				}
			} catch (error) {}

			await fs.writeFile('./templates.json', JSON.stringify(yaml.load(templates)));
			await fs.writeFile('./tags.json', tags);
			console.log('[004] Tags and templates loaded in dev mode...');
		} else {
			const tags = await got.get('https://get.coollabs.io/coolify/service-tags.json').text();
			const response = await got
				.get('https://get.coollabs.io/coolify/service-templates.yaml')
				.text();
			await fs.writeFile('/app/templates.json', JSON.stringify(yaml.load(response)));
			await fs.writeFile('/app/tags.json', tags);
			console.log('[004] Tags and templates loaded...');
		}
	} catch (error) {
		console.log("Couldn't get latest templates.");
		console.log(error);
	}
}
async function initServer() {
	const appId = process.env['COOLIFY_APP_ID'];
	const settings = await prisma.setting.findUnique({ where: { id: '0' } });
	try {
		if (settings.doNotTrack === true) {
			console.log('[000] Telemetry disabled...');
		} else {
			if (settings.sentryDSN !== sentryDSN) {
				await prisma.setting.update({ where: { id: '0' }, data: { sentryDSN } });
			}
			// Initialize Sentry
			// Sentry.init({
			// 	dsn: sentryDSN,
			// 	environment: isDev ? 'development' : 'production',
			// 	release: version
			// });
			// console.log('[000] Sentry initialized...')
		}
	} catch (error) {
		console.error(error);
	}
	try {
		console.log(`[001] Initializing server...`);
		await executeCommand({ command: `docker network create --attachable coolify` });
	} catch (error) {}
	try {
		console.log(`[002] Cleanup stucked builds...`);
		const isOlder = compareVersions('3.8.1', version);
		if (isOlder === 1) {
			await prisma.build.updateMany({
				where: { status: { in: ['running', 'queued'] } },
				data: { status: 'failed' }
			});
		}
	} catch (error) {}
	try {
		console.log('[003] Cleaning up old build sources under /tmp/build-sources/...');
		await fs.rm('/tmp/build-sources', { recursive: true, force: true });
	} catch (error) {
		console.log(error);
	}
}

async function getArch() {
	try {
		const settings = await prisma.setting.findFirst({});
		if (settings && !settings.arch) {
			console.log(`Getting architecture...`);
			await prisma.setting.update({ where: { id: settings.id }, data: { arch: process.arch } });
		}
	} catch (error) {}
}

async function configureRemoteDockers() {
	try {
		const remoteDocker = await prisma.destinationDocker.findMany({
			where: { remoteVerified: true, remoteEngine: true }
		});
		if (remoteDocker.length > 0) {
			console.log(`Verifying Remote Docker Engines...`);
			for (const docker of remoteDocker) {
				console.log('Verifying:', docker.remoteIpAddress);
				await verifyRemoteDockerEngineFn(docker.id);
			}
		}
	} catch (error) {
		console.log(error);
	}
}

async function autoUpdater() {
	try {
		const { default: got } = await import('got');
		const currentVersion = version;
		const { coolify } = await got
			.get('https://get.coollabs.io/versions.json', {
				searchParams: {
					appId: process.env['COOLIFY_APP_ID'] || undefined,
					version: currentVersion
				}
			})
			.json();
		const latestVersion = coolify.main.version;
		const isUpdateAvailable = compareVersions(latestVersion, currentVersion);
		if (isUpdateAvailable === 1) {
			const activeCount = 0;
			if (activeCount === 0) {
				if (!isDev) {
					const { isAutoUpdateEnabled } = await prisma.setting.findFirst();
					if (isAutoUpdateEnabled) {
						await executeCommand({ command: `docker pull coollabsio/coolify:${latestVersion}` });
						await executeCommand({ shell: true, command: `env | grep '^COOLIFY' > .env` });
						await executeCommand({
							command: `sed -i '/COOLIFY_AUTO_UPDATE=/cCOOLIFY_AUTO_UPDATE=${isAutoUpdateEnabled}' .env`
						});
						await executeCommand({
							shell: true,
							command: `docker run --rm -tid --env-file .env -v /var/run/docker.sock:/var/run/docker.sock -v coolify-db coollabsio/coolify:${latestVersion} /bin/sh -c "env | grep COOLIFY > .env && echo 'TAG=${latestVersion}' >> .env && docker stop -t 0 coolify coolify-fluentbit && docker rm coolify coolify-fluentbit && docker compose pull && docker compose up -d --force-recreate"`
						});
					}
				} else {
					console.log('Updating (not really in dev mode).');
				}
			}
		}
	} catch (error) {
		console.log(error);
	}
}

async function checkFluentBit() {
	try {
		if (!isDev) {
			const engine = '/var/run/docker.sock';
			const { id } = await prisma.destinationDocker.findFirst({
				where: { engine, network: 'coolify' }
			});
			const { found } = await checkContainer({
				dockerId: id,
				container: 'coolify-fluentbit',
				remove: true
			});
			if (!found) {
				await executeCommand({ shell: true, command: `env | grep '^COOLIFY' > .env` });
				await executeCommand({ command: `docker compose up -d fluent-bit` });
			}
		}
	} catch (error) {
		console.log(error);
	}
}
async function checkProxies() {
	try {
		const { default: isReachable } = await import('is-port-reachable');
		let portReachable;

		const { arch, ipv4, ipv6 } = await listSettings();

		// Coolify Proxy local
		const engine = '/var/run/docker.sock';
		const localDocker = await prisma.destinationDocker.findFirst({
			where: { engine, network: 'coolify', isCoolifyProxyUsed: true }
		});
		if (localDocker) {
			portReachable = await isReachable(80, { host: ipv4 || ipv6 });
			if (!portReachable) {
				await startTraefikProxy(localDocker.id);
			}
		}
		// Coolify Proxy remote
		const remoteDocker = await prisma.destinationDocker.findMany({
			where: { remoteEngine: true, remoteVerified: true }
		});
		if (remoteDocker.length > 0) {
			for (const docker of remoteDocker) {
				if (docker.isCoolifyProxyUsed) {
					portReachable = await isReachable(80, { host: docker.remoteIpAddress });
					if (!portReachable) {
						await startTraefikProxy(docker.id);
					}
				}
				try {
					await createRemoteEngineConfiguration(docker.id);
				} catch (error) {}
			}
		}
		// TCP Proxies
		const databasesWithPublicPort = await prisma.database.findMany({
			where: { publicPort: { not: null } },
			include: { settings: true, destinationDocker: true }
		});
		for (const database of databasesWithPublicPort) {
			const { destinationDockerId, destinationDocker, publicPort, id } = database;
			if (destinationDockerId && destinationDocker.isCoolifyProxyUsed) {
				const { privatePort } = generateDatabaseConfiguration(database, arch);
				await startTraefikTCPProxy(destinationDocker, id, publicPort, privatePort);
			}
		}
		const wordpressWithFtp = await prisma.wordpress.findMany({
			where: { ftpPublicPort: { not: null } },
			include: { service: { include: { destinationDocker: true } } }
		});
		for (const ftp of wordpressWithFtp) {
			const { service, ftpPublicPort } = ftp;
			const { destinationDockerId, destinationDocker, id } = service;
			if (destinationDockerId && destinationDocker.isCoolifyProxyUsed) {
				await startTraefikTCPProxy(destinationDocker, id, ftpPublicPort, 22, 'wordpressftp');
			}
		}

		// HTTP Proxies
		// const minioInstances = await prisma.minio.findMany({
		// 	where: { publicPort: { not: null } },
		// 	include: { service: { include: { destinationDocker: true } } }
		// });
		// for (const minio of minioInstances) {
		// 	const { service, publicPort } = minio;
		// 	const { destinationDockerId, destinationDocker, id } = service;
		// 	if (destinationDockerId && destinationDocker.isCoolifyProxyUsed) {
		// 		await startTraefikTCPProxy(destinationDocker, id, publicPort, 9000);
		// 	}
		// }
	} catch (error) {}
}

async function copySSLCertificates() {
	try {
		const pAll = await import('p-all');
		const actions = [];
		const certificates = await prisma.certificate.findMany({ include: { team: true } });
		const teamIds = certificates.map((c) => c.teamId);
		const destinations = await prisma.destinationDocker.findMany({
			where: { isCoolifyProxyUsed: true, teams: { some: { id: { in: [...teamIds] } } } }
		});
		for (const certificate of certificates) {
			const { id, key, cert } = certificate;
			const decryptedKey = decrypt(key);
			await fs.writeFile(`/tmp/${id}-key.pem`, decryptedKey);
			await fs.writeFile(`/tmp/${id}-cert.pem`, cert);
			for (const destination of destinations) {
				if (destination.remoteEngine) {
					if (destination.remoteVerified) {
						const { id: dockerId, remoteIpAddress } = destination;
						actions.push(async () => copyRemoteCertificates(id, dockerId, remoteIpAddress));
					}
				} else {
					actions.push(async () => copyLocalCertificates(id));
				}
			}
		}
		await pAll.default(actions, { concurrency: 1 });
	} catch (error) {
		console.log(error);
	} finally {
		await executeCommand({ command: `find /tmp/ -maxdepth 1 -type f -name '*-*.pem' -delete` });
	}
}

async function copyRemoteCertificates(id: string, dockerId: string, remoteIpAddress: string) {
	try {
		await executeCommand({
			command: `scp /tmp/${id}-cert.pem /tmp/${id}-key.pem ${remoteIpAddress}:/tmp/`
		});
		await executeCommand({
			sshCommand: true,
			shell: true,
			dockerId,
			command: `docker exec coolify-proxy sh -c 'test -d /etc/traefik/acme/custom/ || mkdir -p /etc/traefik/acme/custom/'`
		});
		await executeCommand({
			sshCommand: true,
			dockerId,
			command: `docker cp /tmp/${id}-key.pem coolify-proxy:/etc/traefik/acme/custom/`
		});
		await executeCommand({
			sshCommand: true,
			dockerId,
			command: `docker cp /tmp/${id}-cert.pem coolify-proxy:/etc/traefik/acme/custom/`
		});
	} catch (error) {
		console.log({ error });
	}
}
async function copyLocalCertificates(id: string) {
	try {
		await executeCommand({
			command: `docker exec coolify-proxy sh -c 'test -d /etc/traefik/acme/custom/ || mkdir -p /etc/traefik/acme/custom/'`,
			shell: true
		});
		await executeCommand({
			command: `docker cp /tmp/${id}-key.pem coolify-proxy:/etc/traefik/acme/custom/`
		});
		await executeCommand({
			command: `docker cp /tmp/${id}-cert.pem coolify-proxy:/etc/traefik/acme/custom/`
		});
	} catch (error) {
		console.log({ error });
	}
}

async function cleanupStorage() {
	const destinationDockers = await prisma.destinationDocker.findMany();
	let enginesDone = new Set();
	for (const destination of destinationDockers) {
		if (enginesDone.has(destination.engine) || enginesDone.has(destination.remoteIpAddress)) return;
		if (destination.engine) enginesDone.add(destination.engine);
		if (destination.remoteIpAddress) enginesDone.add(destination.remoteIpAddress);
		let force = false;
		let lowDiskSpace = false;
		try {
			let stdout = null;
			if (!isDev) {
				const output = await executeCommand({
					dockerId: destination.id,
					command: `CONTAINER=$(docker ps -lq | head -1) && docker exec $CONTAINER sh -c 'df -kPT /'`,
					shell: true
				});
				stdout = output.stdout;
			} else {
				const output = await executeCommand({
					command: `df -kPT /`
				});
				stdout = output.stdout;
			}
			let lines = stdout.trim().split('\n');
			let header = lines[0];
			let regex =
				/^Filesystem\s+|Type\s+|1024-blocks|\s+Used|\s+Available|\s+Capacity|\s+Mounted on\s*$/g;
			const boundaries = [];
			let match;

			while ((match = regex.exec(header))) {
				boundaries.push(match[0].length);
			}

			boundaries[boundaries.length - 1] = -1;
			const data = lines.slice(1).map((line) => {
				const cl = boundaries.map((boundary) => {
					const column = boundary > 0 ? line.slice(0, boundary) : line;
					line = line.slice(boundary);
					return column.trim();
				});
				return {
					capacity: Number.parseInt(cl[5], 10) / 100
				};
			});
			if (data.length > 0) {
				const { capacity } = data[0];
				if (capacity > 0.8) {
					lowDiskSpace = true;
				}
			}
		} catch (error) {}
		await cleanupDockerStorage(destination.id, lowDiskSpace, force);
	}
}
