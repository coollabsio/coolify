import child from 'child_process';
import util from 'util';
import fs from 'fs/promises';
import yaml from 'js-yaml';
import forge from 'node-forge';
import { uniqueNamesGenerator, adjectives, colors, animals } from 'unique-names-generator';
import type { Config } from 'unique-names-generator';
import generator from 'generate-password';
import crypto from 'crypto';
import { promises as dns } from 'dns';
import { PrismaClient } from '@prisma/client';
import cuid from 'cuid';
import os from 'os';
import sshConfig from 'ssh-config'

import { checkContainer, getEngine, removeContainer } from './docker';
import { day } from './dayjs';
import * as serviceFields from './serviceFields'

export const version = '3.2.0';
export const isDev = process.env.NODE_ENV === 'development';

const algorithm = 'aes-256-ctr';
const customConfig: Config = {
	dictionaries: [adjectives, colors, animals],
	style: 'capital',
	separator: ' ',
	length: 3
};

export const defaultProxyImage = `coolify-haproxy-alpine:latest`;
export const defaultProxyImageTcp = `coolify-haproxy-tcp-alpine:latest`;
export const defaultProxyImageHttp = `coolify-haproxy-http-alpine:latest`;
export const defaultTraefikImage = `traefik:v2.8`;
export function getAPIUrl() {
	if (process.env.GITPOD_WORKSPACE_URL) {
		const { href } = new URL(process.env.GITPOD_WORKSPACE_URL)
		const newURL = href.replace('https://', 'https://3001-').replace(/\/$/, '')
		return newURL
	}
	return isDev ? 'http://localhost:3001' : 'http://localhost:3000';
}
export function getUIUrl() {
	if (process.env.GITPOD_WORKSPACE_URL) {
		const { href } = new URL(process.env.GITPOD_WORKSPACE_URL)
		const newURL = href.replace('https://', 'https://3000-').replace(/\/$/, '')
		return newURL
	}
	return 'http://localhost:3000';
}

const mainTraefikEndpoint = isDev
	? `${getAPIUrl()}/webhooks/traefik/main.json`
	: 'http://coolify:3000/webhooks/traefik/main.json';

const otherTraefikEndpoint = isDev
	? `${getAPIUrl()}/webhooks/traefik/other.json`
	: 'http://coolify:3000/webhooks/traefik/other.json';


export const include: any = {
	destinationDocker: true,
	persistentStorage: true,
	serviceSecret: true,
	minio: true,
	plausibleAnalytics: true,
	vscodeserver: true,
	wordpress: true,
	ghost: true,
	meiliSearch: true,
	umami: true,
	hasura: true,
	fider: true,
};

export const uniqueName = (): string => uniqueNamesGenerator(customConfig);
export const asyncExecShell = util.promisify(child.exec);
export const asyncSleep = (delay: number): Promise<unknown> =>
	new Promise((resolve) => setTimeout(resolve, delay));
export const prisma = new PrismaClient({
	errorFormat: 'minimal'
});

export const base64Encode = (text: string): string => {
	return Buffer.from(text).toString('base64');
};
export const base64Decode = (text: string): string => {
	return Buffer.from(text, 'base64').toString('ascii');
};
export const decrypt = (hashString: string) => {
	if (hashString) {
		const hash = JSON.parse(hashString);
		const decipher = crypto.createDecipheriv(
			algorithm,
			process.env['COOLIFY_SECRET_KEY'],
			Buffer.from(hash.iv, 'hex')
		);
		const decrpyted = Buffer.concat([
			decipher.update(Buffer.from(hash.content, 'hex')),
			decipher.final()
		]);
		return decrpyted.toString();
	}
};
export const encrypt = (text: string) => {
	if (text) {
		const iv = crypto.randomBytes(16);
		const cipher = crypto.createCipheriv(algorithm, process.env['COOLIFY_SECRET_KEY'], iv);
		const encrypted = Buffer.concat([cipher.update(text), cipher.final()]);
		return JSON.stringify({
			iv: iv.toString('hex'),
			content: encrypted.toString('hex')
		});
	}
};

export const supportedServiceTypesAndVersions = [
	{
		name: 'plausibleanalytics',
		fancyName: 'Plausible Analytics',
		baseImage: 'plausible/analytics',
		images: ['bitnami/postgresql:13.2.0', 'yandex/clickhouse-server:21.3.2.5'],
		versions: ['latest', 'stable'],
		recommendedVersion: 'stable',
		ports: {
			main: 8000
		}
	},
	{
		name: 'nocodb',
		fancyName: 'NocoDB',
		baseImage: 'nocodb/nocodb',
		versions: ['latest'],
		recommendedVersion: 'latest',
		ports: {
			main: 8080
		}
	},
	{
		name: 'minio',
		fancyName: 'MinIO',
		baseImage: 'minio/minio',
		versions: ['latest'],
		recommendedVersion: 'latest',
		ports: {
			main: 9001
		}
	},
	{
		name: 'vscodeserver',
		fancyName: 'VSCode Server',
		baseImage: 'codercom/code-server',
		versions: ['latest'],
		recommendedVersion: 'latest',
		ports: {
			main: 8080
		}
	},
	{
		name: 'wordpress',
		fancyName: 'Wordpress',
		baseImage: 'wordpress',
		images: ['bitnami/mysql:5.7'],
		versions: ['latest', 'php8.1', 'php8.0', 'php7.4', 'php7.3'],
		recommendedVersion: 'latest',
		ports: {
			main: 80
		}
	},
	{
		name: 'vaultwarden',
		fancyName: 'Vaultwarden',
		baseImage: 'vaultwarden/server',
		versions: ['latest'],
		recommendedVersion: 'latest',
		ports: {
			main: 80
		}
	},
	{
		name: 'languagetool',
		fancyName: 'LanguageTool',
		baseImage: 'silviof/docker-languagetool',
		versions: ['latest'],
		recommendedVersion: 'latest',
		ports: {
			main: 8010
		}
	},
	{
		name: 'n8n',
		fancyName: 'n8n',
		baseImage: 'n8nio/n8n',
		versions: ['latest'],
		recommendedVersion: 'latest',
		ports: {
			main: 5678
		}
	},
	{
		name: 'uptimekuma',
		fancyName: 'Uptime Kuma',
		baseImage: 'louislam/uptime-kuma',
		versions: ['latest'],
		recommendedVersion: 'latest',
		ports: {
			main: 3001
		}
	},
	{
		name: 'ghost',
		fancyName: 'Ghost',
		baseImage: 'bitnami/ghost',
		images: ['bitnami/mariadb'],
		versions: ['latest'],
		recommendedVersion: 'latest',
		ports: {
			main: 2368
		}
	},
	{
		name: 'meilisearch',
		fancyName: 'Meilisearch',
		baseImage: 'getmeili/meilisearch',
		images: [],
		versions: ['latest'],
		recommendedVersion: 'latest',
		ports: {
			main: 7700
		}
	},
	{
		name: 'umami',
		fancyName: 'Umami',
		baseImage: 'ghcr.io/mikecao/umami',
		images: ['postgres:12-alpine'],
		versions: ['postgresql-latest'],
		recommendedVersion: 'postgresql-latest',
		ports: {
			main: 3000
		}
	},
	{
		name: 'hasura',
		fancyName: 'Hasura',
		baseImage: 'hasura/graphql-engine',
		images: ['postgres:12-alpine'],
		versions: ['latest', 'v2.5.1'],
		recommendedVersion: 'v2.5.1',
		ports: {
			main: 8080
		}
	},
	{
		name: 'fider',
		fancyName: 'Fider',
		baseImage: 'getfider/fider',
		images: ['postgres:12-alpine'],
		versions: ['stable'],
		recommendedVersion: 'stable',
		ports: {
			main: 3000
		}
	},
	// {
	//     name: 'moodle',
	//     fancyName: 'Moodle',
	//     baseImage: 'bitnami/moodle',
	//     images: [],
	//     versions: ['latest', 'v4.0.2'],
	//     recommendedVersion: 'latest',
	//     ports: {
	//         main: 8080
	//     }
	// }
];

export async function checkDoubleBranch(branch: string, projectId: number): Promise<boolean> {
	const applications = await prisma.application.findMany({ where: { branch, projectId } });
	return applications.length > 1;
}
export async function isDNSValid(hostname: any, domain: string): Promise<any> {
	const { isIP } = await import('is-ip');
	let resolves = [];
	try {
		if (isIP(hostname)) {
			resolves = [hostname];
		} else {
			resolves = await dns.resolve4(hostname);
		}
	} catch (error) {
		throw 'Invalid DNS.'
	}

	try {
		let ipDomainFound = false;
		dns.setServers(['1.1.1.1', '8.8.8.8']);
		const dnsResolve = await dns.resolve4(domain);
		if (dnsResolve.length > 0) {
			for (const ip of dnsResolve) {
				if (resolves.includes(ip)) {
					ipDomainFound = true;
				}
			}
		}
		if (!ipDomainFound) throw false;
	} catch (error) {
		throw 'DNS not set'
	}
}


export function getDomain(domain: string): string {
	return domain?.replace('https://', '').replace('http://', '');
}

export async function isDomainConfigured({
	id,
	fqdn,
	checkOwn = false
}: {
	id: string;
	fqdn: string;
	checkOwn?: boolean;
}): Promise<boolean> {
	const domain = getDomain(fqdn);
	const nakedDomain = domain.replace('www.', '');
	const foundApp = await prisma.application.findFirst({
		where: {
			OR: [
				{ fqdn: { endsWith: `//${nakedDomain}` } },
				{ fqdn: { endsWith: `//www.${nakedDomain}` } }
			],
			id: { not: id }
		},
		select: { fqdn: true }
	});
	const foundService = await prisma.service.findFirst({
		where: {
			OR: [
				{ fqdn: { endsWith: `//${nakedDomain}` } },
				{ fqdn: { endsWith: `//www.${nakedDomain}` } },
				{ minio: { apiFqdn: { endsWith: `//${nakedDomain}` } } },
				{ minio: { apiFqdn: { endsWith: `//www.${nakedDomain}` } } }
			],
			id: { not: checkOwn ? undefined : id }
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

export async function getContainerUsage(dockerId: string, container: string): Promise<any> {
	try {
		const { stdout } = await executeDockerCmd({ dockerId, command: `docker container stats ${container} --no-stream --no-trunc --format "{{json .}}"` })
		return JSON.parse(stdout);
	} catch (err) {
		return {
			MemUsage: 0,
			CPUPerc: 0,
			NetIO: 0
		};
	}
}

export async function checkDomainsIsValidInDNS({ hostname, fqdn, dualCerts }): Promise<any> {
	const { isIP } = await import('is-ip');
	const domain = getDomain(fqdn);
	const domainDualCert = domain.includes('www.') ? domain.replace('www.', '') : `www.${domain}`;
	dns.setServers(['1.1.1.1', '8.8.8.8']);
	let resolves = [];
	try {
		if (isIP(hostname)) {
			resolves = [hostname];
		} else {
			resolves = await dns.resolve4(hostname);
		}
	} catch (error) {
		throw { status: 500, message: `Could not determine IP address for ${hostname}.` }
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
			throw { status: 500, message: `DNS not set correctly or propogated.<br>Please check your DNS settings.` }
		} catch (error) {
			throw { status: 500, message: `DNS not set correctly or propogated.<br>Please check your DNS settings.` }
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
			throw { status: 500, message: `DNS not set correctly or propogated.<br>Please check your DNS settings.` }
		} catch (error) {
			throw { status: 500, message: `DNS not set correctly or propogated.<br>Please check your DNS settings.` }
		}
	}
}
export function generateTimestamp(): string {
	return `${day().format('HH:mm:ss.SSS')}`;
}

export async function listServicesWithIncludes(): Promise<any> {
	return await prisma.service.findMany({
		include,
		orderBy: { createdAt: 'desc' }
	});
}

export const supportedDatabaseTypesAndVersions = [
	{
		name: 'mongodb',
		fancyName: 'MongoDB',
		baseImage: 'bitnami/mongodb',
		versions: ['5.0', '4.4', '4.2']
	},
	{ name: 'mysql', fancyName: 'MySQL', baseImage: 'bitnami/mysql', versions: ['8.0', '5.7'] },
	{
		name: 'mariadb',
		fancyName: 'MariaDB',
		baseImage: 'bitnami/mariadb',
		versions: ['10.7', '10.6', '10.5', '10.4', '10.3', '10.2']
	},
	{
		name: 'postgresql',
		fancyName: 'PostgreSQL',
		baseImage: 'bitnami/postgresql',
		versions: ['14.4.0', '13.6.0', '12.10.0', '11.15.0', '10.20.0']
	},
	{
		name: 'redis',
		fancyName: 'Redis',
		baseImage: 'bitnami/redis',
		versions: ['6.2', '6.0', '5.0']
	},
	{ name: 'couchdb', fancyName: 'CouchDB', baseImage: 'bitnami/couchdb', versions: ['3.2.1'] }
];
export async function createRemoteEngineConfiguration(id: string) {
	const homedir = os.homedir();
	const sshKeyFile = `/tmp/id_rsa-${id}`
	const { sshKey: { privateKey }, remoteIpAddress, remotePort, remoteUser } = await prisma.destinationDocker.findFirst({ where: { id }, include: { sshKey: true } })
	await fs.writeFile(sshKeyFile, decrypt(privateKey) + '\n', { encoding: 'utf8', mode: 400 })
	// Needed for remote docker compose
	await asyncExecShell(`eval $(ssh-agent -s) && ssh-add -q ${sshKeyFile}`)
	const config = sshConfig.parse('')
	const found = config.find({ Host: remoteIpAddress })
	if (!found) {
		config.append({
			Host: remoteIpAddress,
			Port: remotePort.toString(),
			User: remoteUser,
			IdentityFile: sshKeyFile,
			StrictHostKeyChecking: 'no'
		})
	}
	try {
		await fs.stat(`${homedir}/.ssh/`)
	} catch (error) {
		await fs.mkdir(`${homedir}/.ssh/`)
	}
	return await fs.writeFile(`${homedir}/.ssh/config`, sshConfig.stringify(config))
}
export async function executeDockerCmd({ dockerId, command }: { dockerId: string, command: string }) {
	let { remoteEngine, remoteIpAddress, remoteUser, engine } = await prisma.destinationDocker.findUnique({ where: { id: dockerId } })
	if (remoteEngine) engine = `ssh://${remoteUser}@${remoteIpAddress}`
	const host = getEngine(engine)
	if (engine.startsWith('ssh://')) {
		await createRemoteEngineConfiguration(dockerId)
	}
	return await asyncExecShell(
		`DOCKER_HOST="${host}" ${command}`
	);
}
export async function startTraefikProxy(id: string): Promise<void> {
	const { engine, network, remoteEngine, remoteIpAddress } = await prisma.destinationDocker.findUnique({ where: { id } })

	const found = await checkContainer({ dockerId: id, container: 'coolify-proxy', remove: true });
	const { id: settingsId, ipv4, ipv6 } = await listSettings();

	if (!found) {
		const { stdout: Config } = await executeDockerCmd({ dockerId: id, command: `docker network inspect ${network} --format '{{json .IPAM.Config }}'` })
		const ip = JSON.parse(Config)[0].Gateway;
		const { publicIp } = await import('public-ip')
		let traefikUrl = mainTraefikEndpoint
		if (remoteEngine) {
			let ip = null
			if (isDev) {
				ip = getAPIUrl()
			} else {
				ip = `http://${ipv4 || ipv6}`
			}
			traefikUrl = `${ip}/webhooks/traefik/remote/${id}`
		}
		await executeDockerCmd({
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
		})
		await prisma.setting.update({ where: { id: settingsId }, data: { proxyHash: null } });
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
	const { id } = destination
	const { stdout: networks } = await executeDockerCmd({
		dockerId: id,
		command:
			`docker ps -a --filter name=coolify-proxy --format '{{json .Networks}}'`
	});
	const configuredNetworks = networks.replace(/"/g, '').replace('\n', '').split(',');
	if (!configuredNetworks.includes(destination.network)) {
		await executeDockerCmd({ dockerId: destination.id, command: `docker network connect ${destination.network} coolify-proxy` })
	}
}

export async function stopTraefikProxy(
	id: string
): Promise<{ stdout: string; stderr: string } | Error> {
	const found = await checkContainer({ dockerId: id, container: 'coolify-proxy' });
	await prisma.destinationDocker.update({
		where: { id },
		data: { isCoolifyProxyUsed: false }
	});
	const { id: settingsId } = await prisma.setting.findFirst({});
	await prisma.setting.update({ where: { id: settingsId }, data: { proxyHash: null } });
	try {
		if (found) {
			await executeDockerCmd({
				dockerId: id,
				command:
					`docker stop -t 0 coolify-proxy && docker rm coolify-proxy`
			});

		}
	} catch (error) {
		return error;
	}
}

export async function configureNetworkCoolifyProxy(engine: string): Promise<void> {
	const host = getEngine(engine);
	const destinations = await prisma.destinationDocker.findMany({ where: { engine } });
	const { stdout: networks } = await asyncExecShell(
		`DOCKER_HOST="${host}" docker ps -a --filter name=coolify-haproxy --format '{{json .Networks}}'`
	);
	const configuredNetworks = networks.replace(/"/g, '').replace('\n', '').split(',');
	for (const destination of destinations) {
		if (!configuredNetworks.includes(destination.network)) {
			await asyncExecShell(
				`DOCKER_HOST="${host}" docker network connect ${destination.network} coolify-haproxy`
			);
		}
	}
}
export async function listSettings(): Promise<any> {
	const settings = await prisma.setting.findFirst({});
	if (settings.proxyPassword) settings.proxyPassword = decrypt(settings.proxyPassword);
	return settings;
}


export function generatePassword(length = 24, symbols = false): string {
	return generator.generate({
		length,
		numbers: true,
		strict: true,
		symbols
	});
}

export function generateDatabaseConfiguration(database: any):
	| {
		volume: string;
		image: string;
		ulimits: Record<string, unknown>;
		privatePort: number;
		environmentVariables: {
			MYSQL_DATABASE: string;
			MYSQL_PASSWORD: string;
			MYSQL_ROOT_USER: string;
			MYSQL_USER: string;
			MYSQL_ROOT_PASSWORD: string;
		};
	}
	| {
		volume: string;
		image: string;
		ulimits: Record<string, unknown>;
		privatePort: number;
		environmentVariables: {
			MONGODB_ROOT_USER: string;
			MONGODB_ROOT_PASSWORD: string;
		};
	}
	| {
		volume: string;
		image: string;
		ulimits: Record<string, unknown>;
		privatePort: number;
		environmentVariables: {
			MARIADB_ROOT_USER: string;
			MARIADB_ROOT_PASSWORD: string;
			MARIADB_USER: string;
			MARIADB_PASSWORD: string;
			MARIADB_DATABASE: string;
		};
	}
	| {
		volume: string;
		image: string;
		ulimits: Record<string, unknown>;
		privatePort: number;
		environmentVariables: {
			POSTGRESQL_POSTGRES_PASSWORD: string;
			POSTGRESQL_USERNAME: string;
			POSTGRESQL_PASSWORD: string;
			POSTGRESQL_DATABASE: string;
		};
	}
	| {
		volume: string;
		image: string;
		ulimits: Record<string, unknown>;
		privatePort: number;
		environmentVariables: {
			REDIS_AOF_ENABLED: string;
			REDIS_PASSWORD: string;
		};
	}
	| {
		volume: string;
		image: string;
		ulimits: Record<string, unknown>;
		privatePort: number;
		environmentVariables: {
			COUCHDB_PASSWORD: string;
			COUCHDB_USER: string;
		};
	} {
	const {
		id,
		dbUser,
		dbUserPassword,
		rootUser,
		rootUserPassword,
		defaultDatabase,
		version,
		type,
		settings: { appendOnly }
	} = database;
	const baseImage = getDatabaseImage(type);
	if (type === 'mysql') {
		return {
			privatePort: 3306,
			environmentVariables: {
				MYSQL_USER: dbUser,
				MYSQL_PASSWORD: dbUserPassword,
				MYSQL_ROOT_PASSWORD: rootUserPassword,
				MYSQL_ROOT_USER: rootUser,
				MYSQL_DATABASE: defaultDatabase
			},
			image: `${baseImage}:${version}`,
			volume: `${id}-${type}-data:/bitnami/mysql/data`,
			ulimits: {}
		};
	} else if (type === 'mariadb') {
		return {
			privatePort: 3306,
			environmentVariables: {
				MARIADB_ROOT_USER: rootUser,
				MARIADB_ROOT_PASSWORD: rootUserPassword,
				MARIADB_USER: dbUser,
				MARIADB_PASSWORD: dbUserPassword,
				MARIADB_DATABASE: defaultDatabase
			},
			image: `${baseImage}:${version}`,
			volume: `${id}-${type}-data:/bitnami/mariadb`,
			ulimits: {}
		};
	} else if (type === 'mongodb') {
		return {
			privatePort: 27017,
			environmentVariables: {
				MONGODB_ROOT_USER: rootUser,
				MONGODB_ROOT_PASSWORD: rootUserPassword
			},
			image: `${baseImage}:${version}`,
			volume: `${id}-${type}-data:/bitnami/mongodb`,
			ulimits: {}
		};
	} else if (type === 'postgresql') {
		return {
			privatePort: 5432,
			environmentVariables: {
				POSTGRESQL_POSTGRES_PASSWORD: rootUserPassword,
				POSTGRESQL_PASSWORD: dbUserPassword,
				POSTGRESQL_USERNAME: dbUser,
				POSTGRESQL_DATABASE: defaultDatabase
			},
			image: `${baseImage}:${version}`,
			volume: `${id}-${type}-data:/bitnami/postgresql`,
			ulimits: {}
		};
	} else if (type === 'redis') {
		return {
			privatePort: 6379,
			environmentVariables: {
				REDIS_PASSWORD: dbUserPassword,
				REDIS_AOF_ENABLED: appendOnly ? 'yes' : 'no'
			},
			image: `${baseImage}:${version}`,
			volume: `${id}-${type}-data:/bitnami/redis/data`,
			ulimits: {}
		};
	} else if (type === 'couchdb') {
		return {
			privatePort: 5984,
			environmentVariables: {
				COUCHDB_PASSWORD: dbUserPassword,
				COUCHDB_USER: dbUser
			},
			image: `${baseImage}:${version}`,
			volume: `${id}-${type}-data:/bitnami/couchdb`,
			ulimits: {}
		};
	}
}

export function getDatabaseImage(type: string): string {
	const found = supportedDatabaseTypesAndVersions.find((t) => t.name === type);
	if (found) {
		return found.baseImage;
	}
	return '';
}

export function getDatabaseVersions(type: string): string[] {
	const found = supportedDatabaseTypesAndVersions.find((t) => t.name === type);
	if (found) {
		return found.versions;
	}
	return [];
}


export type ComposeFile = {
	version: ComposerFileVersion;
	services: Record<string, ComposeFileService>;
	networks: Record<string, ComposeFileNetwork>;
	volumes?: Record<string, ComposeFileVolume>;
};

export type ComposeFileService = {
	container_name: string;
	image?: string;
	networks: string[];
	environment?: Record<string, unknown>;
	volumes?: string[];
	ulimits?: unknown;
	labels?: string[];
	env_file?: string[];
	extra_hosts?: string[];
	restart: ComposeFileRestartOption;
	depends_on?: string[];
	command?: string;
	ports?: string[];
	build?: {
		context: string;
		dockerfile: string;
		args?: Record<string, unknown>;
	} | string;
	deploy?: {
		restart_policy?: {
			condition?: string;
			delay?: string;
			max_attempts?: number;
			window?: string;
		};
	};
};

export type ComposerFileVersion =
	| '3.8'
	| '3.7'
	| '3.6'
	| '3.5'
	| '3.4'
	| '3.3'
	| '3.2'
	| '3.1'
	| '3.0'
	| '2.4'
	| '2.3'
	| '2.2'
	| '2.1'
	| '2.0';

export type ComposeFileRestartOption = 'no' | 'always' | 'on-failure' | 'unless-stopped';

export type ComposeFileNetwork = {
	external: boolean;
};

export type ComposeFileVolume = {
	external?: boolean;
	name?: string;
};

export async function makeLabelForStandaloneDatabase({ id, image, volume }) {
	const database = await prisma.database.findFirst({ where: { id } });
	delete database.destinationDockerId;
	delete database.createdAt;
	delete database.updatedAt;
	return [
		'coolify.managed=true',
		`coolify.version=${version}`,
		`coolify.type=standalone-database`,
		`coolify.configuration=${base64Encode(
			JSON.stringify({
				version,
				image,
				volume,
				...database
			})
		)}`
	];
}
export const createDirectories = async ({
	repository,
	buildId
}: {
	repository: string;
	buildId: string;
}): Promise<{ workdir: string; repodir: string }> => {
	const repodir = `/tmp/build-sources/${repository}/`;
	const workdir = `/tmp/build-sources/${repository}/${buildId}`;

	await asyncExecShell(`mkdir -p ${workdir}`);

	return {
		workdir,
		repodir
	};
};


export async function stopDatabaseContainer(
	database: any
): Promise<boolean> {
	let everStarted = false;
	const {
		id,
		destinationDockerId,
		destinationDocker: { engine, id: dockerId }
	} = database;
	if (destinationDockerId) {
		try {
			const { stdout } = await executeDockerCmd({ dockerId, command: `docker inspect --format '{{json .State}}' ${id}` })

			if (stdout) {
				everStarted = true;
				await removeContainer({ id, dockerId });
			}
		} catch (error) {
			//
		}
	}
	return everStarted;
}


export async function stopTcpHttpProxy(
	id: string,
	destinationDocker: any,
	publicPort: number,
	forceName: string = null
): Promise<{ stdout: string; stderr: string } | Error> {
	const { id: dockerId } = destinationDocker;
	let container = `${id}-${publicPort}`;
	if (forceName) container = forceName;
	const found = await checkContainer({ dockerId, container });
	try {
		if (found) {
			return await executeDockerCmd({
				dockerId,
				command:
					`docker stop -t 0 ${container} && docker rm ${container}`
			});

		}
	} catch (error) {
		return error;
	}
}


export async function updatePasswordInDb(database, user, newPassword, isRoot) {
	const {
		id,
		type,
		rootUser,
		rootUserPassword,
		dbUser,
		dbUserPassword,
		defaultDatabase,
		destinationDockerId,
		destinationDocker: { id: dockerId }
	} = database;
	if (destinationDockerId) {
		if (type === 'mysql') {
			await executeDockerCmd({
				dockerId,
				command: `docker exec ${id} mysql -u ${rootUser} -p${rootUserPassword} -e \"ALTER USER '${user}'@'%' IDENTIFIED WITH caching_sha2_password BY '${newPassword}';\"`
			})
		} else if (type === 'mariadb') {
			await executeDockerCmd({
				dockerId,
				command: `docker exec ${id} mysql -u ${rootUser} -p${rootUserPassword} -e \"SET PASSWORD FOR '${user}'@'%' = PASSWORD('${newPassword}');\"`
			})

		} else if (type === 'postgresql') {
			if (isRoot) {
				await executeDockerCmd({
					dockerId,
					command: `docker exec ${id} psql postgresql://postgres:${rootUserPassword}@${id}:5432/${defaultDatabase} -c "ALTER role postgres WITH PASSWORD '${newPassword}'"`
				})
			} else {
				await executeDockerCmd({
					dockerId,
					command: `docker exec ${id} psql postgresql://${dbUser}:${dbUserPassword}@${id}:5432/${defaultDatabase} -c "ALTER role ${user} WITH PASSWORD '${newPassword}'"`
				})
			}
		} else if (type === 'mongodb') {
			await executeDockerCmd({
				dockerId,
				command: `docker exec ${id} mongo 'mongodb://${rootUser}:${rootUserPassword}@${id}:27017/admin?readPreference=primary&ssl=false' --eval "db.changeUserPassword('${user}','${newPassword}')"`
			})

		} else if (type === 'redis') {
			await executeDockerCmd({
				dockerId,
				command: `docker exec ${id} redis-cli -u redis://${dbUserPassword}@${id}:6379 --raw CONFIG SET requirepass ${newPassword}`
			})

		}
	}
}
export async function getFreeExposedPort(id, exposePort, dockerId, remoteIpAddress) {
	const { default: getPort } = await import('get-port');
	const applicationUsed = await (
		await prisma.application.findMany({
			where: { exposePort: { not: null }, id: { not: id }, destinationDockerId: dockerId },
			select: { exposePort: true }
		})
	).map((a) => a.exposePort);
	const serviceUsed = await (
		await prisma.service.findMany({
			where: { exposePort: { not: null }, id: { not: id }, destinationDockerId: dockerId },
			select: { exposePort: true }
		})
	).map((a) => a.exposePort);
	const usedPorts = [...applicationUsed, ...serviceUsed];
	if (remoteIpAddress) {
		const { default: checkPort } = await import('is-port-reachable');
		const found = await checkPort(exposePort, { host: remoteIpAddress });
		if (!found) {
			return exposePort
		}
		return false
	}
	return await getPort({ port: Number(exposePort), exclude: usedPorts });

}
export async function getFreePublicPort(id, dockerId) {
	const { default: getPort, portNumbers } = await import('get-port');
	const data = await prisma.setting.findFirst();
	const { minPort, maxPort } = data;

	const dbUsed = await (
		await prisma.database.findMany({
			where: { publicPort: { not: null }, id: { not: id }, destinationDockerId: dockerId },
			select: { publicPort: true }
		})
	).map((a) => a.publicPort);
	const wpFtpUsed = await (
		await prisma.wordpress.findMany({
			where: { ftpPublicPort: { not: null }, id: { not: id }, service: { destinationDockerId: dockerId } },
			select: { ftpPublicPort: true }
		})
	).map((a) => a.ftpPublicPort);
	const wpUsed = await (
		await prisma.wordpress.findMany({
			where: { mysqlPublicPort: { not: null }, id: { not: id }, service: { destinationDockerId: dockerId } },
			select: { mysqlPublicPort: true }
		})
	).map((a) => a.mysqlPublicPort);
	const minioUsed = await (
		await prisma.minio.findMany({
			where: { publicPort: { not: null }, id: { not: id }, service: { destinationDockerId: dockerId } },
			select: { publicPort: true }
		})
	).map((a) => a.publicPort);
	const usedPorts = [...dbUsed, ...wpFtpUsed, ...wpUsed, ...minioUsed];
	return await getPort({ port: portNumbers(minPort, maxPort), exclude: usedPorts });
}

export async function startTraefikTCPProxy(
	destinationDocker: any,
	id: string,
	publicPort: number,
	privatePort: number,
	type?: string
): Promise<{ stdout: string; stderr: string } | Error> {
	const { network, id: dockerId, remoteEngine } = destinationDocker;
	const container = `${id}-${publicPort}`;
	const found = await checkContainer({ dockerId, container, remove: true });
	const {  ipv4, ipv6 } = await listSettings();

	let dependentId = id;
	if (type === 'wordpressftp') dependentId = `${id}-ftp`;
	const foundDependentContainer = await checkContainer({ dockerId, container: dependentId, remove: true });
	try {
		if (foundDependentContainer && !found) {
			const { stdout: Config } = await executeDockerCmd({
				dockerId,
				command: `docker network inspect ${network} --format '{{json .IPAM.Config }}'`
			})

			const ip = JSON.parse(Config)[0].Gateway;
			let traefikUrl = otherTraefikEndpoint
			if (remoteEngine) {
				let ip = null
				if (isDev) {
					ip = getAPIUrl()
				} else {
					ip = `http://${ipv4 || ipv6}`
				}
				traefikUrl = `${ip}/webhooks/traefik/other.json`
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
							'--providers.http.pollTimeout=2s',
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
			await executeDockerCmd({
				dockerId,
				command: `docker compose -f /tmp/docker-compose-${id}.yaml up -d`
			})
			await fs.rm(`/tmp/docker-compose-${id}.yaml`);
		}
		if (!foundDependentContainer && found) {
			await executeDockerCmd({
				dockerId,
				command: `docker stop -t 0 ${container} && docker rm ${container}`
			})
		}
	} catch (error) {
		console.log(error);
		return error;
	}
}

export async function getServiceFromDB({ id, teamId }: { id: string; teamId: string }): Promise<any> {
	const settings = await prisma.setting.findFirst();
	const body = await prisma.service.findFirst({
		where: { id, teams: { some: { id: teamId === '0' ? undefined : teamId } } },
		include
	});
	let { type } = body
	type = fixType(type)

	if (body?.serviceSecret.length > 0) {
		body.serviceSecret = body.serviceSecret.map((s) => {
			s.value = decrypt(s.value);
			return s;
		});
	}
	body[type] = { ...body[type], ...getUpdateableFields(type, body[type]) }
	return { ...body, settings };
}

export function getServiceImage(type: string): string {
	const found = supportedServiceTypesAndVersions.find((t) => t.name === type);
	if (found) {
		return found.baseImage;
	}
	return '';
}

export function getServiceImages(type: string): string[] {
	const found = supportedServiceTypesAndVersions.find((t) => t.name === type);
	if (found) {
		return found.images;
	}
	return [];
}

export async function configureServiceType({
	id,
	type
}: {
	id: string;
	type: string;
}): Promise<void> {
	if (type === 'plausibleanalytics') {
		const password = encrypt(generatePassword());
		const postgresqlUser = cuid();
		const postgresqlPassword = encrypt(generatePassword());
		const postgresqlDatabase = 'plausibleanalytics';
		const secretKeyBase = encrypt(generatePassword(64));

		await prisma.service.update({
			where: { id },
			data: {
				type,
				plausibleAnalytics: {
					create: {
						postgresqlDatabase,
						postgresqlUser,
						postgresqlPassword,
						password,
						secretKeyBase
					}
				}
			}
		});
	} else if (type === 'nocodb') {
		await prisma.service.update({
			where: { id },
			data: { type }
		});
	} else if (type === 'minio') {
		const rootUser = cuid();
		const rootUserPassword = encrypt(generatePassword());
		await prisma.service.update({
			where: { id },
			data: { type, minio: { create: { rootUser, rootUserPassword } } }
		});
	} else if (type === 'vscodeserver') {
		const password = encrypt(generatePassword());
		await prisma.service.update({
			where: { id },
			data: { type, vscodeserver: { create: { password } } }
		});
	} else if (type === 'wordpress') {
		const mysqlUser = cuid();
		const mysqlPassword = encrypt(generatePassword());
		const mysqlRootUser = cuid();
		const mysqlRootUserPassword = encrypt(generatePassword());
		await prisma.service.update({
			where: { id },
			data: {
				type,
				wordpress: { create: { mysqlPassword, mysqlRootUserPassword, mysqlRootUser, mysqlUser } }
			}
		});
	} else if (type === 'vaultwarden') {
		await prisma.service.update({
			where: { id },
			data: {
				type
			}
		});
	} else if (type === 'languagetool') {
		await prisma.service.update({
			where: { id },
			data: {
				type
			}
		});
	} else if (type === 'n8n') {
		await prisma.service.update({
			where: { id },
			data: {
				type
			}
		});
	} else if (type === 'uptimekuma') {
		await prisma.service.update({
			where: { id },
			data: {
				type
			}
		});
	} else if (type === 'ghost') {
		const defaultEmail = `${cuid()}@example.com`;
		const defaultPassword = encrypt(generatePassword());
		const mariadbUser = cuid();
		const mariadbPassword = encrypt(generatePassword());
		const mariadbRootUser = cuid();
		const mariadbRootUserPassword = encrypt(generatePassword());

		await prisma.service.update({
			where: { id },
			data: {
				type,
				ghost: {
					create: {
						defaultEmail,
						defaultPassword,
						mariadbUser,
						mariadbPassword,
						mariadbRootUser,
						mariadbRootUserPassword
					}
				}
			}
		});
	} else if (type === 'meilisearch') {
		const masterKey = encrypt(generatePassword(32));
		await prisma.service.update({
			where: { id },
			data: {
				type,
				meiliSearch: { create: { masterKey } }
			}
		});
	} else if (type === 'umami') {
		const umamiAdminPassword = encrypt(generatePassword());
		const postgresqlUser = cuid();
		const postgresqlPassword = encrypt(generatePassword());
		const postgresqlDatabase = 'umami';
		const hashSalt = encrypt(generatePassword(64));
		await prisma.service.update({
			where: { id },
			data: {
				type,
				umami: {
					create: {
						umamiAdminPassword,
						postgresqlDatabase,
						postgresqlPassword,
						postgresqlUser,
						hashSalt
					}
				}
			}
		});
	} else if (type === 'hasura') {
		const postgresqlUser = cuid();
		const postgresqlPassword = encrypt(generatePassword());
		const postgresqlDatabase = 'hasura';
		const graphQLAdminPassword = encrypt(generatePassword());
		await prisma.service.update({
			where: { id },
			data: {
				type,
				hasura: {
					create: {
						postgresqlDatabase,
						postgresqlPassword,
						postgresqlUser,
						graphQLAdminPassword
					}
				}
			}
		});
	} else if (type === 'fider') {
		const postgresqlUser = cuid();
		const postgresqlPassword = encrypt(generatePassword());
		const postgresqlDatabase = 'fider';
		const jwtSecret = encrypt(generatePassword(64, true));
		await prisma.service.update({
			where: { id },
			data: {
				type,
				fider: {
					create: {
						postgresqlDatabase,
						postgresqlPassword,
						postgresqlUser,
						jwtSecret
					}
				}
			}
		});
	} else if (type === 'moodle') {
		const defaultUsername = cuid();
		const defaultPassword = encrypt(generatePassword());
		const defaultEmail = `${cuid()} @example.com`;
		const mariadbUser = cuid();
		const mariadbPassword = encrypt(generatePassword());
		const mariadbDatabase = 'moodle_db';
		const mariadbRootUser = cuid();
		const mariadbRootUserPassword = encrypt(generatePassword());
		await prisma.service.update({
			where: { id },
			data: {
				type,
				moodle: {
					create: {
						defaultUsername,
						defaultPassword,
						defaultEmail,
						mariadbUser,
						mariadbPassword,
						mariadbDatabase,
						mariadbRootUser,
						mariadbRootUserPassword
					}
				}
			}
		});
	} else {
		await prisma.service.update({
			where: { id },
			data: {
				type
			}
		});
	}
}

export async function removeService({ id }: { id: string }): Promise<void> {
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
	await prisma.serviceSecret.deleteMany({ where: { serviceId: id } });

	await prisma.service.delete({ where: { id } });
}

export function saveUpdateableFields(type: string, data: any) {
	let update = {};
	if (type && serviceFields[type]) {
		serviceFields[type].map((k) => {
			let temp = data[k.name]
			if (temp) {
				if (k.isEncrypted) {
					temp = encrypt(temp)
				}
				if (k.isLowerCase) {
					temp = temp.toLowerCase()
				}
				if (k.isNumber) {
					temp = Number(temp)
				}
				if (k.isBoolean) {
					temp = Boolean(temp)
				}
			}
			update[k.name] = temp
		});
	}
	return update
}

export function getUpdateableFields(type: string, data: any) {
	let update = {};
	if (type && serviceFields[type]) {
		serviceFields[type].map((k) => {
			let temp = data[k.name]
			if (temp) {
				if (k.isEncrypted) {
					temp = decrypt(temp)
				}
				update[k.name] = temp
			}
			update[k.name] = temp
		});
	}
	return update
}

export function fixType(type) {
	// Hack to fix the type case sensitivity...
	if (type === 'plausibleanalytics') type = 'plausibleAnalytics';
	if (type === 'meilisearch') type = 'meiliSearch';
	return type
}

export const getServiceMainPort = (service: string) => {
	const serviceType = supportedServiceTypesAndVersions.find((s) => s.name === service);
	if (serviceType) {
		return serviceType.ports.main;
	}
	return null;
};


export function makeLabelForServices(type) {
	return [
		'coolify.managed=true',
		`coolify.version = ${version} `,
		`coolify.type = service`,
		`coolify.service.type = ${type} `
	];
}
export function errorHandler({ status = 500, message = 'Unknown error.' }: { status: number, message: string | any }) {
	if (message.message) message = message.message
	throw { status, message };
}
export async function generateSshKeyPair(): Promise<{ publicKey: string; privateKey: string }> {
	return await new Promise((resolve, reject) => {
		forge.pki.rsa.generateKeyPair({ bits: 4096, workers: -1 }, function (err, keys) {
			if (keys) {
				resolve({
					publicKey: forge.ssh.publicKeyToOpenSSH(keys.publicKey),
					privateKey: forge.ssh.privateKeyToOpenSSH(keys.privateKey)
				});
			} else {
				reject(keys);
			}
		});
	});
}

export async function stopBuild(buildId, applicationId) {
	let count = 0;
	await new Promise<void>(async (resolve, reject) => {
		const { destinationDockerId, status } = await prisma.build.findFirst({ where: { id: buildId } });
		const { engine, id: dockerId } = await prisma.destinationDocker.findFirst({ where: { id: destinationDockerId } });
		const host = getEngine(engine);
		let interval = setInterval(async () => {
			try {
				if (status === 'failed') {
					clearInterval(interval);
					return resolve();
				}
				if (count > 100) {
					clearInterval(interval);
					return reject(new Error('Build canceled'));
				}

				const { stdout: buildContainers } = await asyncExecShell(
					`DOCKER_HOST = ${host} docker container ls--filter "label=coolify.buildId=${buildId}" --format '{{json .}}'`
				);
				if (buildContainers) {
					const containersArray = buildContainers.trim().split('\n');
					for (const container of containersArray) {
						const containerObj = JSON.parse(container);
						const id = containerObj.ID;
						if (!containerObj.Names.startsWith(`${applicationId} `)) {
							await removeContainer({ id, dockerId });
							await cleanupDB(buildId);
							clearInterval(interval);
							return resolve();
						}
					}
				}
				count++;
			} catch (error) { }
		}, 100);
	});
}

async function cleanupDB(buildId: string) {
	const data = await prisma.build.findUnique({ where: { id: buildId } });
	if (data?.status === 'queued' || data?.status === 'running') {
		await prisma.build.update({ where: { id: buildId }, data: { status: 'failed' } });
	}
}

export function convertTolOldVolumeNames(type) {
	if (type === 'nocodb') {
		return 'nc'
	}
}
// export async function getAvailableServices(): Promise<any> {
// 	const { data } = await axios.get(`https://gist.githubusercontent.com/andrasbacsai/4aac36d8d6214dbfc34fa78110554a50/raw/5b27e6c37d78aaeedc1148d797112c827a2f43cf/availableServices.json`)
// 	return data
// 
export async function cleanupDockerStorage(dockerId, lowDiskSpace, force) {
	// Cleanup old coolify images
	try {
		let { stdout: images } = await executeDockerCmd({ dockerId, command: `docker images coollabsio/coolify --filter before="coollabsio/coolify:${version}" -q | xargs` })

		images = images.trim();
		if (images) {
			await executeDockerCmd({ dockerId, command: `docker rmi -f ${images}" -q | xargs` })
		}
	} catch (error) {
		//console.log(error);
	}
	if (lowDiskSpace || force) {
		if (isDev) {
			if (!force) console.log(`[DEV MODE] Low disk space: ${lowDiskSpace}`);
			return
		}
		try {
			await executeDockerCmd({ dockerId, command: `docker container prune -f` })
		} catch (error) {
			//console.log(error);
		}
		try {
			await executeDockerCmd({ dockerId, command: `docker image prune -f` })
		} catch (error) {
			//console.log(error);
		}
		try {
			await executeDockerCmd({ dockerId, command: `docker image prune -a -f` })
		} catch (error) {
			//console.log(error);
		}
	}
}