import type { Permission, Setting, Team, TeamInvitation, User } from '@prisma/client';
import { prisma } from '../prisma';
import bcrypt from 'bcryptjs';
import crypto from 'crypto';
import { promises as dns } from 'dns';
import fs from 'fs/promises';
import { uniqueNamesGenerator, adjectives, colors, animals } from 'unique-names-generator';
import type { Config } from 'unique-names-generator';
import { env } from '../env';
import { day } from './dayjs';
import { executeCommand } from './executeCommand';
import { saveBuildLog } from './logging';
import { checkContainer } from './docker';
import yaml from 'js-yaml';

const customConfig: Config = {
	dictionaries: [adjectives, colors, animals],
	style: 'capital',
	separator: ' ',
	length: 3
};
const algorithm = 'aes-256-ctr';
export const isDev = env.NODE_ENV === 'development';
export const version = '3.13.0';
export const sentryDSN =
	'https://409f09bcb7af47928d3e0f46b78987f3@o1082494.ingest.sentry.io/4504236622217216';
export const defaultTraefikImage = `traefik:v2.8`;
export function getAPIUrl() {
	if (process.env.GITPOD_WORKSPACE_URL) {
		const { href } = new URL(process.env.GITPOD_WORKSPACE_URL);
		const newURL = href.replace('https://', 'https://3001-').replace(/\/$/, '');
		return newURL;
	}
	if (process.env.CODESANDBOX_HOST) {
		return `https://${process.env.CODESANDBOX_HOST.replace(/\$PORT/, '3001')}`;
	}
	return isDev ? 'http://host.docker.internal:3001' : 'http://localhost:3000';
}

export function getUIUrl() {
	if (process.env.GITPOD_WORKSPACE_URL) {
		const { href } = new URL(process.env.GITPOD_WORKSPACE_URL);
		const newURL = href.replace('https://', 'https://3000-').replace(/\/$/, '');
		return newURL;
	}
	if (process.env.CODESANDBOX_HOST) {
		return `https://${process.env.CODESANDBOX_HOST.replace(/\$PORT/, '3000')}`;
	}
	return 'http://localhost:3000';
}
const mainTraefikEndpoint = isDev
	? `${getAPIUrl()}/webhooks/traefik/main.json`
	: 'http://coolify:3000/webhooks/traefik/main.json';

const otherTraefikEndpoint = isDev
	? `${getAPIUrl()}/webhooks/traefik/other.json`
	: 'http://coolify:3000/webhooks/traefik/other.json';

export async function listSettings(): Promise<Setting | null> {
	return await prisma.setting.findUnique({ where: { id: '0' } });
}
export async function getCurrentUser(
	userId: string
): Promise<(User & { permission: Permission[]; teams: Team[] }) | null> {
	return await prisma.user.findUnique({
		where: { id: userId },
		include: { teams: true, permission: true }
	});
}
export async function getTeamInvitation(userId: string): Promise<TeamInvitation[]> {
	return await prisma.teamInvitation.findMany({ where: { uid: userId } });
}

export async function hashPassword(password: string): Promise<string> {
	const saltRounds = 15;
	return bcrypt.hash(password, saltRounds);
}
export async function comparePassword(password: string, hashedPassword: string): Promise<boolean> {
	return bcrypt.compare(password, hashedPassword);
}
export const uniqueName = (): string => uniqueNamesGenerator(customConfig);

export const decrypt = (hashString: string) => {
	if (hashString) {
		try {
			const hash = JSON.parse(hashString);
			const decipher = crypto.createDecipheriv(
				algorithm,
				env.COOLIFY_SECRET_KEY,
				Buffer.from(hash.iv, 'hex')
			);
			const decrpyted = Buffer.concat([
				decipher.update(Buffer.from(hash.content, 'hex')),
				decipher.final()
			]);
			return decrpyted.toString();
		} catch (error) {
			if (error instanceof Error) {
				console.log({ decryptionError: error.message });
			}
			return hashString;
		}
	}
	return false;
};

export function generateRangeArray(start: number, end: number) {
	return Array.from({ length: end - start }, (_v, k) => k + start);
}
export function generateTimestamp(): string {
	return `${day().format('HH:mm:ss.SSS')}`;
}
export const encrypt = (text: string) => {
	if (text) {
		const iv = crypto.randomBytes(16);
		const cipher = crypto.createCipheriv(algorithm, env.COOLIFY_SECRET_KEY, iv);
		const encrypted = Buffer.concat([cipher.update(text.trim()), cipher.final()]);
		return JSON.stringify({
			iv: iv.toString('hex'),
			content: encrypted.toString('hex')
		});
	}
	return false;
};

export async function getTemplates() {
	const templatePath = isDev ? './templates.json' : '/app/templates.json';
	const open = await fs.open(templatePath, 'r');
	try {
		let data = await open.readFile({ encoding: 'utf-8' });
		let jsonData = JSON.parse(data);
		if (isARM(process.arch)) {
			jsonData = jsonData.filter((d: { arch: string }) => d.arch !== 'amd64');
		}
		return jsonData;
	} catch (error) {
		return [];
	} finally {
		await open?.close();
	}
}
export function isARM(arch: string) {
	if (arch === 'arm' || arch === 'arm64' || arch === 'aarch' || arch === 'aarch64') {
		return true;
	}
	return false;
}

export async function removeService({ id }: { id: string }): Promise<void> {
	await prisma.serviceSecret.deleteMany({ where: { serviceId: id } });
	await prisma.serviceSetting.deleteMany({ where: { serviceId: id } });
	await prisma.servicePersistentStorage.deleteMany({ where: { serviceId: id } });
	await prisma.meiliSearch.deleteMany({ where: { serviceId: id } });
	await prisma.fider.deleteMany({ where: { serviceId: id } });
	await prisma.ghost.deleteMany({ where: { serviceId: id } });
	await prisma.umami.deleteMany({ where: { serviceId: id } });
	await prisma.hasura.deleteMany({ where: { serviceId: id } });
	await prisma.plausibleAnalytics.deleteMany({ where: { serviceId: id } });
	await prisma.minio.deleteMany({ where: { serviceId: id } });
	await prisma.vscodeserver.deleteMany({ where: { serviceId: id } });
	await prisma.wordpress.deleteMany({ where: { serviceId: id } });
	await prisma.glitchTip.deleteMany({ where: { serviceId: id } });
	await prisma.moodle.deleteMany({ where: { serviceId: id } });
	await prisma.appwrite.deleteMany({ where: { serviceId: id } });
	await prisma.searxng.deleteMany({ where: { serviceId: id } });
	await prisma.weblate.deleteMany({ where: { serviceId: id } });
	await prisma.taiga.deleteMany({ where: { serviceId: id } });

	await prisma.service.delete({ where: { id } });
}

export const createDirectories = async ({
	repository,
	buildId
}: {
	repository: string;
	buildId: string;
}): Promise<{ workdir: string; repodir: string }> => {
	if (repository) repository = repository.replaceAll(' ', '');
	const repodir = `/tmp/build-sources/${repository}/`;
	const workdir = `/tmp/build-sources/${repository}/${buildId}`;
	let workdirFound = false;
	try {
		workdirFound = !!(await fs.stat(workdir));
	} catch (error) {}
	if (workdirFound) {
		await executeCommand({ command: `rm -fr ${workdir}` });
	}
	await executeCommand({ command: `mkdir -p ${workdir}` });
	return {
		workdir,
		repodir
	};
};

export async function saveDockerRegistryCredentials({ url, username, password, workdir }) {
	if (!username || !password) {
		return null;
	}

	let decryptedPassword = decrypt(password);
	const location = `${workdir}/.docker`;

	try {
		await fs.mkdir(`${workdir}/.docker`);
	} catch (error) {
		console.log(error);
	}
	const payload = JSON.stringify({
		auths: {
			[url]: {
				auth: Buffer.from(`${username}:${decryptedPassword}`).toString('base64')
			}
		}
	});
	await fs.writeFile(`${location}/config.json`, payload);
	return location;
}
export function getDomain(domain: string): string {
	if (domain) {
		return domain?.replace('https://', '').replace('http://', '');
	} else {
		return '';
	}
}

export async function isDomainConfigured({
	id,
	fqdn,
	checkOwn = false,
	remoteIpAddress = undefined
}: {
	id: string;
	fqdn: string;
	checkOwn?: boolean;
	remoteIpAddress?: string;
}): Promise<boolean> {
	const domain = getDomain(fqdn);
	const nakedDomain = domain.replace('www.', '');
	const foundApp = await prisma.application.findFirst({
		where: {
			OR: [
				{ fqdn: { endsWith: `//${nakedDomain}` } },
				{ fqdn: { endsWith: `//www.${nakedDomain}` } },
				{ dockerComposeConfiguration: { contains: `//${nakedDomain}` } },
				{ dockerComposeConfiguration: { contains: `//www.${nakedDomain}` } }
			],
			id: { not: id },
			destinationDocker: {
				remoteIpAddress
			}
		},
		select: { fqdn: true }
	});
	const foundService = await prisma.service.findFirst({
		where: {
			OR: [
				{ fqdn: { endsWith: `//${nakedDomain}` } },
				{ fqdn: { endsWith: `//www.${nakedDomain}` } }
			],
			id: { not: checkOwn ? undefined : id },
			destinationDocker: {
				remoteIpAddress
			}
		},
		select: { fqdn: true }
	});

	const coolifyFqdn = await prisma.setting.findFirst({
		where: {
			OR: [
				{ fqdn: { endsWith: `//${nakedDomain}` } },
				{ fqdn: { endsWith: `//www.${nakedDomain}` } }
			],
			id: { not: id }
		},
		select: { fqdn: true }
	});
	return !!(foundApp || foundService || coolifyFqdn);
}

export async function checkExposedPort({
	id,
	configuredPort,
	exposePort,
	engine,
	remoteEngine,
	remoteIpAddress
}: {
	id: string;
	configuredPort?: number;
	exposePort: number;
	engine: string;
	remoteEngine: boolean;
	remoteIpAddress?: string;
}) {
	if (exposePort < 1024 || exposePort > 65535) {
		throw { status: 500, message: `Exposed Port needs to be between 1024 and 65535.` };
	}
	if (configuredPort) {
		if (configuredPort !== exposePort) {
			const availablePort = await getFreeExposedPort(
				id,
				exposePort,
				engine,
				remoteEngine,
				remoteIpAddress
			);
			if (availablePort.toString() !== exposePort.toString()) {
				throw { status: 500, message: `Port ${exposePort} is already in use.` };
			}
		}
	} else {
		const availablePort = await getFreeExposedPort(
			id,
			exposePort,
			engine,
			remoteEngine,
			remoteIpAddress
		);
		if (availablePort.toString() !== exposePort.toString()) {
			throw { status: 500, message: `Port ${exposePort} is already in use.` };
		}
	}
}
export async function getFreeExposedPort(id, exposePort, engine, remoteEngine, remoteIpAddress) {
	const { default: checkPort } = await import('is-port-reachable');
	if (remoteEngine) {
		const applicationUsed = await (
			await prisma.application.findMany({
				where: {
					exposePort: { not: null },
					id: { not: id },
					destinationDocker: { remoteIpAddress }
				},
				select: { exposePort: true }
			})
		).map((a) => a.exposePort);
		const serviceUsed = await (
			await prisma.service.findMany({
				where: {
					exposePort: { not: null },
					id: { not: id },
					destinationDocker: { remoteIpAddress }
				},
				select: { exposePort: true }
			})
		).map((a) => a.exposePort);
		const usedPorts = [...applicationUsed, ...serviceUsed];
		if (usedPorts.includes(exposePort)) {
			return false;
		}
		const found = await checkPort(exposePort, { host: remoteIpAddress });
		if (!found) {
			return exposePort;
		}
		return false;
	} else {
		const applicationUsed = await (
			await prisma.application.findMany({
				where: { exposePort: { not: null }, id: { not: id }, destinationDocker: { engine } },
				select: { exposePort: true }
			})
		).map((a) => a.exposePort);
		const serviceUsed = await (
			await prisma.service.findMany({
				where: { exposePort: { not: null }, id: { not: id }, destinationDocker: { engine } },
				select: { exposePort: true }
			})
		).map((a) => a.exposePort);
		const usedPorts = [...applicationUsed, ...serviceUsed];
		if (usedPorts.includes(exposePort)) {
			return false;
		}
		const found = await checkPort(exposePort, { host: 'localhost' });
		if (!found) {
			return exposePort;
		}
		return false;
	}
}

export async function checkDomainsIsValidInDNS({ hostname, fqdn, dualCerts }): Promise<any> {
	const { isIP } = await import('is-ip');
	const domain = getDomain(fqdn);
	const domainDualCert = domain.includes('www.') ? domain.replace('www.', '') : `www.${domain}`;

	const { DNSServers } = await listSettings();
	if (DNSServers) {
		dns.setServers([...DNSServers.split(',')]);
	}

	let resolves = [];
	try {
		if (isIP(hostname)) {
			resolves = [hostname];
		} else {
			resolves = await dns.resolve4(hostname);
		}
	} catch (error) {
		throw { status: 500, message: `Could not determine IP address for ${hostname}.` };
	}

	if (dualCerts) {
		try {
			const ipDomain = await dns.resolve4(domain);
			const ipDomainDualCert = await dns.resolve4(domainDualCert);

			let ipDomainFound = false;
			let ipDomainDualCertFound = false;

			for (const ip of ipDomain) {
				if (resolves.includes(ip)) {
					ipDomainFound = true;
				}
			}
			for (const ip of ipDomainDualCert) {
				if (resolves.includes(ip)) {
					ipDomainDualCertFound = true;
				}
			}
			if (ipDomainFound && ipDomainDualCertFound) return { status: 200 };
			throw {
				status: 500,
				message: `DNS not set correctly or propogated.<br>Please check your DNS settings.`
			};
		} catch (error) {
			throw {
				status: 500,
				message: `DNS not set correctly or propogated.<br>Please check your DNS settings.`
			};
		}
	} else {
		try {
			const ipDomain = await dns.resolve4(domain);
			let ipDomainFound = false;
			for (const ip of ipDomain) {
				if (resolves.includes(ip)) {
					ipDomainFound = true;
				}
			}
			if (ipDomainFound) return { status: 200 };
			throw {
				status: 500,
				message: `DNS not set correctly or propogated.<br>Please check your DNS settings.`
			};
		} catch (error) {
			throw {
				status: 500,
				message: `DNS not set correctly or propogated.<br>Please check your DNS settings.`
			};
		}
	}
}
export const setDefaultConfiguration = async (data: any) => {
	let {
		buildPack,
		port,
		installCommand,
		startCommand,
		buildCommand,
		publishDirectory,
		baseDirectory,
		dockerFileLocation,
		dockerComposeFileLocation,
		denoMainFile
	} = data;
	//@ts-ignore
	const template = scanningTemplates[buildPack];
	if (!port) {
		port = template?.port || 3000;

		if (buildPack === 'static') port = 80;
		else if (buildPack === 'node') port = 3000;
		else if (buildPack === 'php') port = 80;
		else if (buildPack === 'python') port = 8000;
	}
	if (!installCommand && buildPack !== 'static' && buildPack !== 'laravel')
		installCommand = template?.installCommand || 'yarn install';
	if (!startCommand && buildPack !== 'static' && buildPack !== 'laravel')
		startCommand = template?.startCommand || 'yarn start';
	if (!buildCommand && buildPack !== 'static' && buildPack !== 'laravel')
		buildCommand = template?.buildCommand || null;
	if (!publishDirectory) publishDirectory = template?.publishDirectory || null;
	if (baseDirectory) {
		if (!baseDirectory.startsWith('/')) baseDirectory = `/${baseDirectory}`;
		if (baseDirectory.endsWith('/') && baseDirectory !== '/')
			baseDirectory = baseDirectory.slice(0, -1);
	}
	if (dockerFileLocation) {
		if (!dockerFileLocation.startsWith('/')) dockerFileLocation = `/${dockerFileLocation}`;
		if (dockerFileLocation.endsWith('/')) dockerFileLocation = dockerFileLocation.slice(0, -1);
	} else {
		dockerFileLocation = '/Dockerfile';
	}
	if (dockerComposeFileLocation) {
		if (!dockerComposeFileLocation.startsWith('/'))
			dockerComposeFileLocation = `/${dockerComposeFileLocation}`;
		if (dockerComposeFileLocation.endsWith('/'))
			dockerComposeFileLocation = dockerComposeFileLocation.slice(0, -1);
	} else {
		dockerComposeFileLocation = '/Dockerfile';
	}
	if (!denoMainFile) {
		denoMainFile = 'main.ts';
	}

	return {
		buildPack,
		port,
		installCommand,
		startCommand,
		buildCommand,
		publishDirectory,
		baseDirectory,
		dockerFileLocation,
		dockerComposeFileLocation,
		denoMainFile
	};
};

export const scanningTemplates = {
	'@sveltejs/kit': {
		buildPack: 'nodejs'
	},
	astro: {
		buildPack: 'astro'
	},
	'@11ty/eleventy': {
		buildPack: 'eleventy'
	},
	svelte: {
		buildPack: 'svelte'
	},
	'@nestjs/core': {
		buildPack: 'nestjs'
	},
	next: {
		buildPack: 'nextjs'
	},
	nuxt: {
		buildPack: 'nuxtjs'
	},
	'react-scripts': {
		buildPack: 'react'
	},
	'parcel-bundler': {
		buildPack: 'static'
	},
	'@vue/cli-service': {
		buildPack: 'vuejs'
	},
	vuejs: {
		buildPack: 'vuejs'
	},
	gatsby: {
		buildPack: 'gatsby'
	},
	'preact-cli': {
		buildPack: 'react'
	}
};

export async function cleanupDB(buildId: string, applicationId: string) {
	const data = await prisma.build.findUnique({ where: { id: buildId } });
	if (data?.status === 'queued' || data?.status === 'running') {
		await prisma.build.update({ where: { id: buildId }, data: { status: 'canceled' } });
	}
	await saveBuildLog({ line: 'Canceled.', buildId, applicationId });
}

export const base64Encode = (text: string): string => {
	return Buffer.from(text).toString('base64');
};
export const base64Decode = (text: string): string => {
	return Buffer.from(text, 'base64').toString('ascii');
};
function parseSecret(secret, isBuild) {
	if (secret.value.includes('$')) {
		secret.value = secret.value.replaceAll('$', '$$$$');
	}
	if (secret.value.includes('\\n')) {
		if (isBuild) {
			return `ARG ${secret.name}=${secret.value}`;
		} else {
			return `${secret.name}=${secret.value}`;
		}
	} else if (secret.value.includes(' ')) {
		if (isBuild) {
			return `ARG ${secret.name}='${secret.value}'`;
		} else {
			return `${secret.name}='${secret.value}'`;
		}
	} else {
		if (isBuild) {
			return `ARG ${secret.name}=${secret.value}`;
		} else {
			return `${secret.name}=${secret.value}`;
		}
	}
}
export function generateSecrets(
	secrets: Array<any>,
	pullmergeRequestId: string,
	isBuild = false,
	port = null,
	compose = false
): Array<string> {
	const envs = [];
	const isPRMRSecret = secrets.filter((s) => s.isPRMRSecret);
	const normalSecrets = secrets.filter((s) => !s.isPRMRSecret);
	if (pullmergeRequestId && isPRMRSecret.length > 0) {
		isPRMRSecret.forEach((secret) => {
			if ((isBuild && !secret.isBuildSecret) || (!isBuild && secret.isBuildSecret)) {
				return;
			}
			const build = isBuild && secret.isBuildSecret;
			envs.push(parseSecret(secret, compose ? false : build));
		});
	}
	if (!pullmergeRequestId && normalSecrets.length > 0) {
		normalSecrets.forEach((secret) => {
			if ((isBuild && !secret.isBuildSecret) || (!isBuild && secret.isBuildSecret)) {
				return;
			}
			const build = isBuild && secret.isBuildSecret;
			envs.push(parseSecret(secret, compose ? false : build));
		});
	}
	const portFound = envs.filter((env) => env.startsWith('PORT'));
	if (portFound.length === 0 && port && !isBuild) {
		envs.push(`PORT=${port}`);
	}
	const nodeEnv = envs.filter((env) => env.startsWith('NODE_ENV'));
	if (nodeEnv.length === 0 && !isBuild) {
		envs.push(`NODE_ENV=production`);
	}
	return envs;
}
export function decryptApplication(application: any) {
	if (application) {
		if (application?.gitSource?.githubApp?.clientSecret) {
			application.gitSource.githubApp.clientSecret =
				decrypt(application.gitSource.githubApp.clientSecret) || null;
		}
		if (application?.gitSource?.githubApp?.webhookSecret) {
			application.gitSource.githubApp.webhookSecret =
				decrypt(application.gitSource.githubApp.webhookSecret) || null;
		}
		if (application?.gitSource?.githubApp?.privateKey) {
			application.gitSource.githubApp.privateKey =
				decrypt(application.gitSource.githubApp.privateKey) || null;
		}
		if (application?.gitSource?.gitlabApp?.appSecret) {
			application.gitSource.gitlabApp.appSecret =
				decrypt(application.gitSource.gitlabApp.appSecret) || null;
		}
		if (application?.secrets.length > 0) {
			application.secrets = application.secrets.map((s: any) => {
				s.value = decrypt(s.value) || null;
				return s;
			});
		}

		return application;
	}
}
export async function pushToRegistry(
	application: any,
	workdir: string,
	tag: string,
	imageName: string,
	customTag: string
) {
	const location = `${workdir}/.docker`;
	const tagCommand = `docker tag ${application.id}:${tag} ${imageName}:${customTag}`;
	const pushCommand = `docker --config ${location} push ${imageName}:${customTag}`;
	await executeCommand({
		dockerId: application.destinationDockerId,
		command: tagCommand
	});
	await executeCommand({
		dockerId: application.destinationDockerId,
		command: pushCommand
	});
}

export async function getContainerUsage(dockerId: string, container: string): Promise<any> {
	try {
		const { stdout } = await executeCommand({
			dockerId,
			command: `docker container stats ${container} --no-stream --no-trunc --format "{{json .}}"`
		});
		return JSON.parse(stdout);
	} catch (err) {
		return {
			MemUsage: 0,
			CPUPerc: 0,
			NetIO: 0
		};
	}
}
export function fixType(type) {
	return type?.replaceAll(' ', '').toLowerCase() || null;
}
const compareSemanticVersions = (a: string, b: string) => {
	const a1 = a.split('.');
	const b1 = b.split('.');
	const len = Math.min(a1.length, b1.length);
	for (let i = 0; i < len; i++) {
		const a2 = +a1[i] || 0;
		const b2 = +b1[i] || 0;
		if (a2 !== b2) {
			return a2 > b2 ? 1 : -1;
		}
	}
	return b1.length - a1.length;
};
export async function getTags(type: string) {
	try {
		if (type) {
			const tagsPath = isDev ? './tags.json' : '/app/tags.json';
			const data = await fs.readFile(tagsPath, 'utf8');
			let tags = JSON.parse(data);
			if (tags) {
				tags = tags.find((tag: any) => tag.name.includes(type));
				tags.tags = tags.tags.sort(compareSemanticVersions).reverse();
				return tags;
			}
		}
	} catch (error) {
		return [];
	}
}
export function makeLabelForServices(type) {
	return [
		'coolify.managed=true',
		`coolify.version=${version}`,
		`coolify.type=service`,
		`coolify.service.type=${type}`
	];
}
export const asyncSleep = (delay: number): Promise<unknown> =>
	new Promise((resolve) => setTimeout(resolve, delay));

export async function startTraefikTCPProxy(
	destinationDocker: any,
	id: string,
	publicPort: number,
	privatePort: number,
	type?: string
): Promise<{ stdout: string; stderr: string }> {
	const { network, id: dockerId, remoteEngine } = destinationDocker;
	const container = `${id}-${publicPort}`;
	const { found } = await checkContainer({ dockerId, container, remove: true });
	const { ipv4, ipv6 } = await listSettings();

	let dependentId = id;
	if (type === 'wordpressftp') dependentId = `${id}-ftp`;
	const { found: foundDependentContainer } = await checkContainer({
		dockerId,
		container: dependentId,
		remove: true
	});
	if (foundDependentContainer && !found) {
		const { stdout: Config } = await executeCommand({
			dockerId,
			command: `docker network inspect ${network} --format '{{json .IPAM.Config }}'`
		});

		const ip = JSON.parse(Config)[0].Gateway;
		let traefikUrl = otherTraefikEndpoint;
		if (remoteEngine) {
			let ip = null;
			if (isDev) {
				ip = getAPIUrl();
			} else {
				ip = `http://${ipv4 || ipv6}:3000`;
			}
			traefikUrl = `${ip}/webhooks/traefik/other.json`;
		}
		const tcpProxy = {
			version: '3.8',
			services: {
				[`${id}-${publicPort}`]: {
					container_name: container,
					image: defaultTraefikImage,
					command: [
						`--entrypoints.tcp.address=:${publicPort}`,
						`--entryPoints.tcp.forwardedHeaders.insecure=true`,
						`--providers.http.endpoint=${traefikUrl}?id=${id}&privatePort=${privatePort}&publicPort=${publicPort}&type=tcp&address=${dependentId}`,
						'--providers.http.pollTimeout=10s',
						'--log.level=error'
					],
					ports: [`${publicPort}:${publicPort}`],
					extra_hosts: ['host.docker.internal:host-gateway', `host.docker.internal: ${ip}`],
					volumes: ['/var/run/docker.sock:/var/run/docker.sock'],
					networks: ['coolify-infra', network]
				}
			},
			networks: {
				[network]: {
					external: false,
					name: network
				},
				'coolify-infra': {
					external: false,
					name: 'coolify-infra'
				}
			}
		};
		await fs.writeFile(`/tmp/docker-compose-${id}.yaml`, yaml.dump(tcpProxy));
		await executeCommand({
			dockerId,
			command: `docker compose -f /tmp/docker-compose-${id}.yaml up -d`
		});
		await fs.rm(`/tmp/docker-compose-${id}.yaml`);
	}
	if (!foundDependentContainer && found) {
		await executeCommand({
			dockerId,
			command: `docker stop -t 0 ${container} && docker rm ${container}`,
			shell: true
		});
	}
}
export async function startTraefikProxy(id: string): Promise<void> {
	const { engine, network, remoteEngine, remoteIpAddress } =
		await prisma.destinationDocker.findUnique({ where: { id } });
	const { found } = await checkContainer({
		dockerId: id,
		container: 'coolify-proxy',
		remove: true
	});
	const { id: settingsId, ipv4, ipv6 } = await listSettings();

	if (!found) {
		const { stdout: coolifyNetwork } = await executeCommand({
			dockerId: id,
			command: `docker network ls --filter 'name=coolify-infra' --no-trunc --format "{{json .}}"`
		});

		if (!coolifyNetwork) {
			await executeCommand({
				dockerId: id,
				command: `docker network create --attachable coolify-infra`
			});
		}
		const { stdout: Config } = await executeCommand({
			dockerId: id,
			command: `docker network inspect ${network} --format '{{json .IPAM.Config }}'`
		});
		const ip = JSON.parse(Config)[0].Gateway;
		let traefikUrl = mainTraefikEndpoint;
		if (remoteEngine) {
			let ip = null;
			if (isDev) {
				ip = getAPIUrl();
			} else {
				ip = `http://${ipv4 || ipv6}:3000`;
			}
			traefikUrl = `${ip}/webhooks/traefik/remote/${id}`;
		}
		await executeCommand({
			dockerId: id,
			command: `docker run --restart always \
			--add-host 'host.docker.internal:host-gateway' \
			${ip ? `--add-host 'host.docker.internal:${ip}'` : ''} \
			-v coolify-traefik-letsencrypt:/etc/traefik/acme \
			-v /var/run/docker.sock:/var/run/docker.sock \
			--network coolify-infra \
			-p "80:80" \
			-p "443:443" \
			--name coolify-proxy \
			-d ${defaultTraefikImage} \
			--entrypoints.web.address=:80 \
			--entrypoints.web.forwardedHeaders.insecure=true \
			--entrypoints.websecure.address=:443 \
			--entrypoints.websecure.forwardedHeaders.insecure=true \
			--providers.docker=true \
			--providers.docker.exposedbydefault=false \
			--providers.http.endpoint=${traefikUrl} \
			--providers.http.pollTimeout=5s \
			--certificatesresolvers.letsencrypt.acme.httpchallenge=true \
			--certificatesresolvers.letsencrypt.acme.storage=/etc/traefik/acme/acme.json \
			--certificatesresolvers.letsencrypt.acme.httpchallenge.entrypoint=web \
			--log.level=error`
		});
		await prisma.destinationDocker.update({
			where: { id },
			data: { isCoolifyProxyUsed: true }
		});
	}
	// Configure networks for local docker engine
	if (engine) {
		const destinations = await prisma.destinationDocker.findMany({ where: { engine } });
		for (const destination of destinations) {
			await configureNetworkTraefikProxy(destination);
		}
	}
	// Configure networks for remote docker engine
	if (remoteEngine) {
		const destinations = await prisma.destinationDocker.findMany({ where: { remoteIpAddress } });
		for (const destination of destinations) {
			await configureNetworkTraefikProxy(destination);
		}
	}
}

export async function configureNetworkTraefikProxy(destination: any): Promise<void> {
	const { id } = destination;
	const { stdout: networks } = await executeCommand({
		dockerId: id,
		command: `docker ps -a --filter name=coolify-proxy --format '{{json .Networks}}'`
	});
	const configuredNetworks = networks.replace(/"/g, '').replace('\n', '').split(',');
	if (!configuredNetworks.includes(destination.network)) {
		await executeCommand({
			dockerId: destination.id,
			command: `docker network connect ${destination.network} coolify-proxy`
		});
	}
}

export async function stopTraefikProxy(id: string): Promise<{ stdout: string; stderr: string }> {
	const { found } = await checkContainer({ dockerId: id, container: 'coolify-proxy' });
	await prisma.destinationDocker.update({
		where: { id },
		data: { isCoolifyProxyUsed: false }
	});
	if (found) {
		return await executeCommand({
			dockerId: id,
			command: `docker stop -t 0 coolify-proxy && docker rm coolify-proxy`,
			shell: true
		});
	}
	return { stdout: '', stderr: '' };
}
