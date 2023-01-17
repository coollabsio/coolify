import { exec } from 'node:child_process';
import util from 'util';
import fs from 'fs/promises';
import yaml from 'js-yaml';
import forge from 'node-forge';
import { uniqueNamesGenerator, adjectives, colors, animals } from 'unique-names-generator';
import type { Config } from 'unique-names-generator';
import generator from 'generate-password';
import crypto from 'crypto';
import { promises as dns } from 'dns';
import * as Sentry from '@sentry/node';
import { PrismaClient } from '@prisma/client';
import os from 'os';
import sshConfig from 'ssh-config';
import jsonwebtoken from 'jsonwebtoken';
import { checkContainer, removeContainer } from './docker';
import { day } from './dayjs';
import { saveBuildLog, saveDockerRegistryCredentials } from './buildPacks/common';
import { scheduler } from './scheduler';
import type { ExecaChildProcess } from 'execa';

export const version = '3.12.12';
export const isDev = process.env.NODE_ENV === 'development';
export const sentryDSN =
	'https://409f09bcb7af47928d3e0f46b78987f3@o1082494.ingest.sentry.io/4504236622217216';
const algorithm = 'aes-256-ctr';
const customConfig: Config = {
	dictionaries: [adjectives, colors, animals],
	style: 'capital',
	separator: ' ',
	length: 3
};

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

export const uniqueName = (): string => uniqueNamesGenerator(customConfig);
export const asyncExecShellStream = async ({
	debug,
	buildId,
	applicationId,
	command,
	engine
}: {
	debug: boolean;
	buildId: string;
	applicationId: string;
	command: string;
	engine: string;
}) => {
	return await new Promise(async (resolve, reject) => {
		const { execaCommand } = await import('execa');
		const subprocess = execaCommand(command, {
			env: { DOCKER_BUILDKIT: '1', DOCKER_HOST: engine }
		});
		const logs = [];
		subprocess.stdout.on('data', async (data) => {
			const stdout = data.toString();
			const array = stdout.split('\n');
			for (const line of array) {
				if (line !== '\n' && line !== '') {
					const log = {
						line: `${line.replace('\n', '')}`,
						buildId,
						applicationId
					};
					logs.push(log);
					if (debug) {
						await saveBuildLog(log);
					}
				}
			}
		});
		subprocess.stderr.on('data', async (data) => {
			const stderr = data.toString();
			const array = stderr.split('\n');
			for (const line of array) {
				if (line !== '\n' && line !== '') {
					const log = {
						line: `${line.replace('\n', '')}`,
						buildId,
						applicationId
					};
					logs.push(log);
					if (debug) {
						await saveBuildLog(log);
					}
				}
			}
		});
		subprocess.on('exit', async (code) => {
			await asyncSleep(1000);
			if (code === 0) {
				resolve(code);
			} else {
				if (!debug) {
					for (const log of logs) {
						await saveBuildLog(log);
					}
				}
				reject(code);
			}
		});
	});
};

export const asyncSleep = (delay: number): Promise<unknown> =>
	new Promise((resolve) => setTimeout(resolve, delay));
export const prisma = new PrismaClient({
	errorFormat: 'minimal'
	// log: [
	// 	{
	// 	  emit: 'event',
	// 	  level: 'query',
	// 	},
	// 	{
	// 	  emit: 'stdout',
	// 	  level: 'error',
	// 	},
	// 	{
	// 	  emit: 'stdout',
	// 	  level: 'info',
	// 	},
	// 	{
	// 	  emit: 'stdout',
	// 	  level: 'warn',
	// 	},
	//   ],
});

// prisma.$on('query', (e) => {
// console.log({e})
// console.log('Query: ' + e.query)
// console.log('Params: ' + e.params)
// console.log('Duration: ' + e.duration + 'ms')
//   })
export const base64Encode = (text: string): string => {
	return Buffer.from(text).toString('base64');
};
export const base64Decode = (text: string): string => {
	return Buffer.from(text, 'base64').toString('ascii');
};
export const decrypt = (hashString: string) => {
	if (hashString) {
		try {
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
		} catch (error) {
			console.log({ decryptionError: error.message });
			return hashString;
		}
	}
};
export const encrypt = (text: string) => {
	if (text) {
		const iv = crypto.randomBytes(16);
		const cipher = crypto.createCipheriv(algorithm, process.env['COOLIFY_SECRET_KEY'], iv);
		const encrypted = Buffer.concat([cipher.update(text.trim()), cipher.final()]);
		return JSON.stringify({
			iv: iv.toString('hex'),
			content: encrypted.toString('hex')
		});
	}
};

export async function checkDoubleBranch(branch: string, projectId: number): Promise<boolean> {
	const applications = await prisma.application.findMany({ where: { branch, projectId } });
	return applications.length > 1;
}
export async function isDNSValid(hostname: any, domain: string): Promise<any> {
	const { isIP } = await import('is-ip');
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
		throw 'Invalid DNS.';
	}

	try {
		let ipDomainFound = false;
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
		throw 'DNS not set';
	}
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
export function generateTimestamp(): string {
	return `${day().format('HH:mm:ss.SSS')}`;
}

export const supportedDatabaseTypesAndVersions = [
	{
		name: 'mongodb',
		fancyName: 'MongoDB',
		baseImage: 'bitnami/mongodb',
		baseImageARM: 'mongo',
		versions: ['5.0', '4.4', '4.2'],
		versionsARM: ['5.0', '4.4', '4.2']
	},
	{
		name: 'mysql',
		fancyName: 'MySQL',
		baseImage: 'bitnami/mysql',
		baseImageARM: 'mysql',
		versions: ['8.0', '5.7'],
		versionsARM: ['8.0', '5.7']
	},
	{
		name: 'mariadb',
		fancyName: 'MariaDB',
		baseImage: 'bitnami/mariadb',
		baseImageARM: 'mariadb',
		versions: ['10.8', '10.7', '10.6', '10.5', '10.4', '10.3', '10.2'],
		versionsARM: ['10.8', '10.7', '10.6', '10.5', '10.4', '10.3', '10.2']
	},
	{
		name: 'postgresql',
		fancyName: 'PostgreSQL',
		baseImage: 'bitnami/postgresql',
		baseImageARM: 'postgres',
		versions: ['14.5.0', '13.8.0', '12.12.0', '11.17.0', '10.22.0'],
		versionsARM: ['14.5', '13.8', '12.12', '11.17', '10.22']
	},
	{
		name: 'redis',
		fancyName: 'Redis',
		baseImage: 'bitnami/redis',
		baseImageARM: 'redis',
		versions: ['7.0', '6.2', '6.0', '5.0'],
		versionsARM: ['7.0', '6.2', '6.0', '5.0']
	},
	{
		name: 'couchdb',
		fancyName: 'CouchDB',
		baseImage: 'bitnami/couchdb',
		baseImageARM: 'couchdb',
		versions: ['3.2.2', '3.1.2', '2.3.1'],
		versionsARM: ['3.2.2', '3.1.2', '2.3.1']
	},
	{
		name: 'edgedb',
		fancyName: 'EdgeDB',
		baseImage: 'edgedb/edgedb',
		versions: ['latest', '2.1', '2.0', '1.4']
	}
];

export async function getFreeSSHLocalPort(id: string): Promise<number | boolean> {
	const { default: isReachable } = await import('is-port-reachable');
	const { remoteIpAddress, sshLocalPort } = await prisma.destinationDocker.findUnique({
		where: { id }
	});
	if (sshLocalPort) {
		return Number(sshLocalPort);
	}

	const data = await prisma.setting.findFirst();
	const { minPort, maxPort } = data;

	const ports = await prisma.destinationDocker.findMany({
		where: { sshLocalPort: { not: null }, remoteIpAddress: { not: remoteIpAddress } }
	});

	const alreadyConfigured = await prisma.destinationDocker.findFirst({
		where: {
			remoteIpAddress,
			id: { not: id },
			sshLocalPort: { not: null }
		}
	});
	if (alreadyConfigured?.sshLocalPort) {
		await prisma.destinationDocker.update({
			where: { id },
			data: { sshLocalPort: alreadyConfigured.sshLocalPort }
		});
		return Number(alreadyConfigured.sshLocalPort);
	}
	const range = generateRangeArray(minPort, maxPort);
	const availablePorts = range.filter((port) => !ports.map((p) => p.sshLocalPort).includes(port));
	for (const port of availablePorts) {
		const found = await isReachable(port, { host: 'localhost' });
		if (!found) {
			await prisma.destinationDocker.update({
				where: { id },
				data: { sshLocalPort: Number(port) }
			});
			return Number(port);
		}
	}
	return false;
}

export async function createRemoteEngineConfiguration(id: string) {
	const homedir = os.homedir();
	const sshKeyFile = `/tmp/id_rsa-${id}`;
	const localPort = await getFreeSSHLocalPort(id);
	const {
		sshKey: { privateKey },
		network,
		remoteIpAddress,
		remotePort,
		remoteUser
	} = await prisma.destinationDocker.findFirst({ where: { id }, include: { sshKey: true } });
	await fs.writeFile(sshKeyFile, decrypt(privateKey) + '\n', { encoding: 'utf8', mode: 400 });
	const config = sshConfig.parse('');
	const Host = `${remoteIpAddress}-remote`;

	try {
		await executeCommand({ command: `ssh-keygen -R ${Host}` });
		await executeCommand({ command: `ssh-keygen -R ${remoteIpAddress}` });
		await executeCommand({ command: `ssh-keygen -R localhost:${localPort}` });
	} catch (error) {}

	const found = config.find({ Host });
	const foundIp = config.find({ Host: remoteIpAddress });

	if (found) config.remove({ Host });
	if (foundIp) config.remove({ Host: remoteIpAddress });

	config.append({
		Host,
		Hostname: remoteIpAddress,
		Port: remotePort.toString(),
		User: remoteUser,
		StrictHostKeyChecking: 'no',
		IdentityFile: sshKeyFile,
		ControlMaster: 'auto',
		ControlPath: `${homedir}/.ssh/coolify-${remoteIpAddress}-%r@%h:%p`,
		ControlPersist: '10m'
	});

	try {
		await fs.stat(`${homedir}/.ssh/`);
	} catch (error) {
		await fs.mkdir(`${homedir}/.ssh/`);
	}
	return await fs.writeFile(`${homedir}/.ssh/config`, sshConfig.stringify(config));
}
export async function executeCommand({
	command,
	dockerId = null,
	sshCommand = false,
	shell = false,
	stream = false,
	buildId,
	applicationId,
	debug
}: {
	command: string;
	sshCommand?: boolean;
	shell?: boolean;
	stream?: boolean;
	dockerId?: string;
	buildId?: string;
	applicationId?: string;
	debug?: boolean;
}): Promise<ExecaChildProcess<string>> {
	const { execa, execaCommand } = await import('execa');
	const { parse } = await import('shell-quote');
	const parsedCommand = parse(command);
	const dockerCommand = parsedCommand[0];
	const dockerArgs = parsedCommand.slice(1);

	if (dockerId) {
		let { remoteEngine, remoteIpAddress, engine } = await prisma.destinationDocker.findUnique({
			where: { id: dockerId }
		});
		if (remoteEngine) {
			await createRemoteEngineConfiguration(dockerId);
			engine = `ssh://${remoteIpAddress}-remote`;
		} else {
			engine = 'unix:///var/run/docker.sock';
		}
		if (process.env.CODESANDBOX_HOST) {
			if (command.startsWith('docker compose')) {
				command = command.replace(/docker compose/gi, 'docker-compose');
			}
		}
		if (sshCommand) {
			if (shell) {
				return execaCommand(`ssh ${remoteIpAddress}-remote ${command}`);
			}
			return await execa('ssh', [`${remoteIpAddress}-remote`, dockerCommand, ...dockerArgs]);
		}
		if (stream) {
			return await new Promise(async (resolve, reject) => {
				let subprocess = null;
				if (shell) {
					subprocess = execaCommand(command, {
						env: { DOCKER_BUILDKIT: '1', DOCKER_HOST: engine }
					});
				} else {
					subprocess = execa(dockerCommand, dockerArgs, {
						env: { DOCKER_BUILDKIT: '1', DOCKER_HOST: engine }
					});
				}
				const logs = [];
				subprocess.stdout.on('data', async (data) => {
					const stdout = data.toString();
					const array = stdout.split('\n');
					for (const line of array) {
						if (line !== '\n' && line !== '') {
							const log = {
								line: `${line.replace('\n', '')}`,
								buildId,
								applicationId
							};
							logs.push(log);
							if (debug) {
								await saveBuildLog(log);
							}
						}
					}
				});
				subprocess.stderr.on('data', async (data) => {
					const stderr = data.toString();
					const array = stderr.split('\n');
					for (const line of array) {
						if (line !== '\n' && line !== '') {
							const log = {
								line: `${line.replace('\n', '')}`,
								buildId,
								applicationId
							};
							logs.push(log);
							if (debug) {
								await saveBuildLog(log);
							}
						}
					}
				});
				subprocess.on('exit', async (code) => {
					if (code === 0) {
						resolve(code);
					} else {
						if (!debug) {
							for (const log of logs) {
								await saveBuildLog(log);
							}
						}
						reject(code);
					}
				});
			});
		} else {
			if (shell) {
				return await execaCommand(command, {
					env: { DOCKER_BUILDKIT: '1', DOCKER_HOST: engine }
				});
			} else {
				return await execa(dockerCommand, dockerArgs, {
					env: { DOCKER_BUILDKIT: '1', DOCKER_HOST: engine }
				});
			}
		}
	} else {
		if (shell) {
			return execaCommand(command, { shell: true });
		}
		return await execa(dockerCommand, dockerArgs);
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
			${isDev ? '-p "8080:8080"' : ''} \
			--name coolify-proxy \
			-d ${defaultTraefikImage} \
			${isDev ? '--api.insecure=true' : ''} \
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

export async function stopTraefikProxy(
	id: string
): Promise<{ stdout: string; stderr: string } | Error> {
	const { found } = await checkContainer({ dockerId: id, container: 'coolify-proxy' });
	await prisma.destinationDocker.update({
		where: { id },
		data: { isCoolifyProxyUsed: false }
	});
	try {
		if (found) {
			await executeCommand({
				dockerId: id,
				command: `docker stop -t 0 coolify-proxy && docker rm coolify-proxy`,
				shell: true
			});
		}
	} catch (error) {
		return error;
	}
}

export async function listSettings(): Promise<any> {
	return await prisma.setting.findUnique({ where: { id: '0' } });
}

export function generateToken() {
	return jsonwebtoken.sign(
		{
			nbf: Math.floor(Date.now() / 1000) - 30
		},
		process.env['COOLIFY_SECRET_KEY']
	);
}
export function generatePassword({
	length = 24,
	symbols = false,
	isHex = false
}: { length?: number; symbols?: boolean; isHex?: boolean } | null): string {
	if (isHex) {
		return crypto.randomBytes(length).toString('hex');
	}
	const password = generator.generate({
		length,
		numbers: true,
		strict: true,
		symbols
	});

	return password;
}

type DatabaseConfiguration =
	| {
			volume: string;
			image: string;
			command?: string;
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
			command?: string;
			ulimits: Record<string, unknown>;
			privatePort: number;
			environmentVariables: {
				MONGO_INITDB_ROOT_USERNAME?: string;
				MONGO_INITDB_ROOT_PASSWORD?: string;
				MONGODB_ROOT_USER?: string;
				MONGODB_ROOT_PASSWORD?: string;
			};
	  }
	| {
			volume: string;
			image: string;
			command?: string;
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
			command?: string;
			ulimits: Record<string, unknown>;
			privatePort: number;
			environmentVariables: {
				POSTGRES_PASSWORD?: string;
				POSTGRES_USER?: string;
				POSTGRES_DB?: string;
				POSTGRESQL_POSTGRES_PASSWORD?: string;
				POSTGRESQL_USERNAME?: string;
				POSTGRESQL_PASSWORD?: string;
				POSTGRESQL_DATABASE?: string;
			};
	  }
	| {
			volume: string;
			image: string;
			command?: string;
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
			command?: string;
			ulimits: Record<string, unknown>;
			privatePort: number;
			environmentVariables: {
				COUCHDB_PASSWORD: string;
				COUCHDB_USER: string;
			};
	  }
	| {
			volume: string;
			image: string;
			command?: string;
			ulimits: Record<string, unknown>;
			privatePort: number;
			environmentVariables: {
				EDGEDB_SERVER_PASSWORD: string;
				EDGEDB_SERVER_USER: string;
				EDGEDB_SERVER_DATABASE: string;
				EDGEDB_SERVER_TLS_CERT_MODE: string;
			};
	  };
export function generateDatabaseConfiguration(database: any, arch: string): DatabaseConfiguration {
	const { id, dbUser, dbUserPassword, rootUser, rootUserPassword, defaultDatabase, version, type } =
		database;
	const baseImage = getDatabaseImage(type, arch);
	if (type === 'mysql') {
		const configuration = {
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
		if (isARM(arch)) {
			configuration.volume = `${id}-${type}-data:/var/lib/mysql`;
		}
		return configuration;
	} else if (type === 'mariadb') {
		const configuration: DatabaseConfiguration = {
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
		if (isARM(arch)) {
			configuration.volume = `${id}-${type}-data:/var/lib/mysql`;
		}
		return configuration;
	} else if (type === 'mongodb') {
		const configuration: DatabaseConfiguration = {
			privatePort: 27017,
			environmentVariables: {
				MONGODB_ROOT_USER: rootUser,
				MONGODB_ROOT_PASSWORD: rootUserPassword
			},
			image: `${baseImage}:${version}`,
			volume: `${id}-${type}-data:/bitnami/mongodb`,
			ulimits: {}
		};
		if (isARM(arch)) {
			configuration.environmentVariables = {
				MONGO_INITDB_ROOT_USERNAME: rootUser,
				MONGO_INITDB_ROOT_PASSWORD: rootUserPassword
			};
			configuration.volume = `${id}-${type}-data:/data/db`;
		}
		return configuration;
	} else if (type === 'postgresql') {
		const configuration: DatabaseConfiguration = {
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
		if (isARM(arch)) {
			configuration.volume = `${id}-${type}-data:/var/lib/postgresql`;
			configuration.environmentVariables = {
				POSTGRES_PASSWORD: dbUserPassword,
				POSTGRES_USER: dbUser,
				POSTGRES_DB: defaultDatabase
			};
		}
		return configuration;
	} else if (type === 'redis') {
		const {
			settings: { appendOnly }
		} = database;
		const configuration: DatabaseConfiguration = {
			privatePort: 6379,
			command: undefined,
			environmentVariables: {
				REDIS_PASSWORD: dbUserPassword,
				REDIS_AOF_ENABLED: appendOnly ? 'yes' : 'no'
			},
			image: `${baseImage}:${version}`,
			volume: `${id}-${type}-data:/bitnami/redis/data`,
			ulimits: {}
		};
		if (isARM(arch)) {
			configuration.volume = `${id}-${type}-data:/data`;
			configuration.command = `/usr/local/bin/redis-server --appendonly ${
				appendOnly ? 'yes' : 'no'
			} --requirepass ${dbUserPassword}`;
		}
		return configuration;
	} else if (type === 'couchdb') {
		const configuration: DatabaseConfiguration = {
			privatePort: 5984,
			environmentVariables: {
				COUCHDB_PASSWORD: dbUserPassword,
				COUCHDB_USER: dbUser
			},
			image: `${baseImage}:${version}`,
			volume: `${id}-${type}-data:/bitnami/couchdb`,
			ulimits: {}
		};
		if (isARM(arch)) {
			configuration.volume = `${id}-${type}-data:/opt/couchdb/data`;
		}
		return configuration;
	} else if (type === 'edgedb') {
		const configuration: DatabaseConfiguration = {
			privatePort: 5656,
			environmentVariables: {
				EDGEDB_SERVER_PASSWORD: rootUserPassword,
				EDGEDB_SERVER_USER: rootUser,
				EDGEDB_SERVER_DATABASE: defaultDatabase,
				EDGEDB_SERVER_TLS_CERT_MODE: 'generate_self_signed'
			},
			image: `${baseImage}:${version}`,
			volume: `${id}-${type}-data:/var/lib/edgedb/data`,
			ulimits: {}
		};
		return configuration;
	}
}
export function isARM(arch: string) {
	if (arch === 'arm' || arch === 'arm64' || arch === 'aarch' || arch === 'aarch64') {
		return true;
	}
	return false;
}
export function getDatabaseImage(type: string, arch: string): string {
	const found = supportedDatabaseTypesAndVersions.find((t) => t.name === type);
	if (found) {
		if (isARM(arch)) {
			return found.baseImageARM || found.baseImage;
		}
		return found.baseImage;
	}
	return '';
}

export function getDatabaseVersions(type: string, arch: string): string[] {
	const found = supportedDatabaseTypesAndVersions.find((t) => t.name === type);
	if (found) {
		if (isARM(arch)) {
			return found.versionsARM || found.versions;
		}
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
	build?:
		| {
				context: string;
				dockerfile: string;
				args?: Record<string, unknown>;
		  }
		| string;
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
		`coolify.name=${database.name}`,
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

export async function stopDatabaseContainer(database: any): Promise<boolean> {
	let everStarted = false;
	const {
		id,
		destinationDockerId,
		destinationDocker: { engine, id: dockerId }
	} = database;
	if (destinationDockerId) {
		try {
			const { stdout } = await executeCommand({
				dockerId,
				command: `docker inspect --format '{{json .State}}' ${id}`
			});

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
	const { found } = await checkContainer({ dockerId, container });
	try {
		if (found) {
			return await executeCommand({
				dockerId,
				command: `docker stop -t 0 ${container} && docker rm ${container}`,
				shell: true
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
			await executeCommand({
				dockerId,
				command: `docker exec ${id} mysql -u ${rootUser} -p${rootUserPassword} -e \"ALTER USER '${user}'@'%' IDENTIFIED WITH caching_sha2_password BY '${newPassword}';\"`
			});
		} else if (type === 'mariadb') {
			await executeCommand({
				dockerId,
				command: `docker exec ${id} mysql -u ${rootUser} -p${rootUserPassword} -e \"SET PASSWORD FOR '${user}'@'%' = PASSWORD('${newPassword}');\"`
			});
		} else if (type === 'postgresql') {
			if (isRoot) {
				await executeCommand({
					dockerId,
					command: `docker exec ${id} psql postgresql://postgres:${rootUserPassword}@${id}:5432/${defaultDatabase} -c "ALTER role postgres WITH PASSWORD '${newPassword}'"`
				});
			} else {
				await executeCommand({
					dockerId,
					command: `docker exec ${id} psql postgresql://${dbUser}:${dbUserPassword}@${id}:5432/${defaultDatabase} -c "ALTER role ${user} WITH PASSWORD '${newPassword}'"`
				});
			}
		} else if (type === 'mongodb') {
			await executeCommand({
				dockerId,
				command: `docker exec ${id} mongo 'mongodb://${rootUser}:${rootUserPassword}@${id}:27017/admin?readPreference=primary&ssl=false' --eval "db.changeUserPassword('${user}','${newPassword}')"`
			});
		} else if (type === 'redis') {
			await executeCommand({
				dockerId,
				command: `docker exec ${id} redis-cli -u redis://${dbUserPassword}@${id}:6379 --raw CONFIG SET requirepass ${newPassword}`
			});
		}
	}
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
export function generateRangeArray(start, end) {
	return Array.from({ length: end - start }, (v, k) => k + start);
}
export async function getFreePublicPort({ id, remoteEngine, engine, remoteIpAddress }) {
	const { default: isReachable } = await import('is-port-reachable');
	const data = await prisma.setting.findFirst();
	const { minPort, maxPort } = data;
	if (remoteEngine) {
		const dbUsed = await (
			await prisma.database.findMany({
				where: {
					publicPort: { not: null },
					id: { not: id },
					destinationDocker: { remoteIpAddress }
				},
				select: { publicPort: true }
			})
		).map((a) => a.publicPort);
		const wpFtpUsed = await (
			await prisma.wordpress.findMany({
				where: {
					ftpPublicPort: { not: null },
					id: { not: id },
					service: { destinationDocker: { remoteIpAddress } }
				},
				select: { ftpPublicPort: true }
			})
		).map((a) => a.ftpPublicPort);
		const wpUsed = await (
			await prisma.wordpress.findMany({
				where: {
					mysqlPublicPort: { not: null },
					id: { not: id },
					service: { destinationDocker: { remoteIpAddress } }
				},
				select: { mysqlPublicPort: true }
			})
		).map((a) => a.mysqlPublicPort);
		const minioUsed = await (
			await prisma.minio.findMany({
				where: {
					publicPort: { not: null },
					id: { not: id },
					service: { destinationDocker: { remoteIpAddress } }
				},
				select: { publicPort: true }
			})
		).map((a) => a.publicPort);
		const usedPorts = [...dbUsed, ...wpFtpUsed, ...wpUsed, ...minioUsed];
		const range = generateRangeArray(minPort, maxPort);
		const availablePorts = range.filter((port) => !usedPorts.includes(port));
		for (const port of availablePorts) {
			const found = await isReachable(port, { host: remoteIpAddress });
			if (!found) {
				return port;
			}
		}
		return false;
	} else {
		const dbUsed = await (
			await prisma.database.findMany({
				where: { publicPort: { not: null }, id: { not: id }, destinationDocker: { engine } },
				select: { publicPort: true }
			})
		).map((a) => a.publicPort);
		const wpFtpUsed = await (
			await prisma.wordpress.findMany({
				where: {
					ftpPublicPort: { not: null },
					id: { not: id },
					service: { destinationDocker: { engine } }
				},
				select: { ftpPublicPort: true }
			})
		).map((a) => a.ftpPublicPort);
		const wpUsed = await (
			await prisma.wordpress.findMany({
				where: {
					mysqlPublicPort: { not: null },
					id: { not: id },
					service: { destinationDocker: { engine } }
				},
				select: { mysqlPublicPort: true }
			})
		).map((a) => a.mysqlPublicPort);
		const minioUsed = await (
			await prisma.minio.findMany({
				where: {
					publicPort: { not: null },
					id: { not: id },
					service: { destinationDocker: { engine } }
				},
				select: { publicPort: true }
			})
		).map((a) => a.publicPort);
		const usedPorts = [...dbUsed, ...wpFtpUsed, ...wpUsed, ...minioUsed];
		const range = generateRangeArray(minPort, maxPort);
		const availablePorts = range.filter((port) => !usedPorts.includes(port));
		for (const port of availablePorts) {
			const found = await isReachable(port, { host: 'localhost' });
			if (!found) {
				return port;
			}
		}
		return false;
	}
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
	const { found } = await checkContainer({ dockerId, container, remove: true });
	const { ipv4, ipv6 } = await listSettings();

	let dependentId = id;
	if (type === 'wordpressftp') dependentId = `${id}-ftp`;
	const { found: foundDependentContainer } = await checkContainer({
		dockerId,
		container: dependentId,
		remove: true
	});
	try {
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
	} catch (error) {
		return error;
	}
}

export async function getServiceFromDB({
	id,
	teamId
}: {
	id: string;
	teamId: string;
}): Promise<any> {
	const settings = await prisma.setting.findFirst();
	const body = await prisma.service.findFirst({
		where: { id, teams: { some: { id: teamId === '0' ? undefined : teamId } } },
		include: {
			destinationDocker: true,
			persistentStorage: true,
			serviceSecret: true,
			serviceSetting: true,
			wordpress: true,
			plausibleAnalytics: true
		}
	});
	if (!body) {
		return null;
	}
	// body.type = fixType(body.type);

	if (body?.serviceSecret.length > 0) {
		body.serviceSecret = body.serviceSecret.map((s) => {
			s.value = decrypt(s.value);
			return s;
		});
	}
	if (body.wordpress) {
		body.wordpress.ftpPassword = decrypt(body.wordpress.ftpPassword);
	}

	return { ...body, settings };
}

export function fixType(type) {
	return type?.replaceAll(' ', '').toLowerCase() || null;
}

export function makeLabelForServices(type) {
	return [
		'coolify.managed=true',
		`coolify.version=${version}`,
		`coolify.type=service`,
		`coolify.service.type=${type}`
	];
}
export function errorHandler({
	status = 500,
	message = 'Unknown error.',
	type = 'normal'
}: {
	status: number;
	message: string | any;
	type?: string | null;
}) {
	if (message.message) message = message.message;
	if (type === 'normal') {
		Sentry.captureException(message);
	}
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
		const { destinationDockerId, status } = await prisma.build.findFirst({
			where: { id: buildId }
		});
		const { id: dockerId } = await prisma.destinationDocker.findFirst({
			where: { id: destinationDockerId }
		});
		const interval = setInterval(async () => {
			try {
				if (status === 'failed' || status === 'canceled') {
					clearInterval(interval);
					return resolve();
				}
				if (count > 15) {
					clearInterval(interval);
					if (scheduler.workers.has('deployApplication')) {
						scheduler.workers.get('deployApplication').postMessage('cancel');
					}
					await cleanupDB(buildId, applicationId);
					return reject(new Error('Canceled.'));
				}
				const { stdout: buildContainers } = await executeCommand({
					dockerId,
					command: `docker container ls --filter "label=coolify.buildId=${buildId}" --format '{{json .}}'`
				});
				if (buildContainers) {
					const containersArray = buildContainers.trim().split('\n');
					for (const container of containersArray) {
						const containerObj = JSON.parse(container);
						const id = containerObj.ID;
						if (!containerObj.Names.startsWith(`${applicationId} `)) {
							await removeContainer({ id, dockerId });
							clearInterval(interval);
							if (scheduler.workers.has('deployApplication')) {
								scheduler.workers.get('deployApplication').postMessage('cancel');
							}
							await cleanupDB(buildId, applicationId);
							return resolve();
						}
					}
				}
				count++;
			} catch (error) {}
		}, 100);
	});
}

async function cleanupDB(buildId: string, applicationId: string) {
	const data = await prisma.build.findUnique({ where: { id: buildId } });
	if (data?.status === 'queued' || data?.status === 'running') {
		await prisma.build.update({ where: { id: buildId }, data: { status: 'canceled' } });
	}
	await saveBuildLog({ line: 'Canceled.', buildId, applicationId });
}

export function convertTolOldVolumeNames(type) {
	if (type === 'nocodb') {
		return 'nc';
	}
}

export async function cleanupDockerStorage(dockerId, lowDiskSpace, force) {
	// Cleanup old coolify images
	try {
		let { stdout: images } = await executeCommand({
			dockerId,
			command: `docker images coollabsio/coolify --filter before="coollabsio/coolify:${version}" -q | xargs -r`,
			shell: true
		});

		images = images.trim();
		if (images) {
			await executeCommand({
				dockerId,
				command: `docker rmi -f ${images}" -q | xargs -r`,
				shell: true
			});
		}
	} catch (error) {}
	if (lowDiskSpace || force) {
		// Cleanup images that are not used
		try {
			await executeCommand({ dockerId, command: `docker image prune -f` });
		} catch (error) {}

		const { numberOfDockerImagesKeptLocally } = await prisma.setting.findUnique({
			where: { id: '0' }
		});
		const { stdout: images } = await executeCommand({
			dockerId,
			command: `docker images|grep -v "<none>"|grep -v REPOSITORY|awk '{print $1, $2}'`,
			shell: true
		});
		const imagesArray = images.trim().replaceAll(' ', ':').split('\n');
		const imagesSet = new Set(imagesArray.map((image) => image.split(':')[0]));
		let deleteImage = [];
		for (const image of imagesSet) {
			let keepImage = [];
			for (const image2 of imagesArray) {
				if (image2.startsWith(image)) {
					if (force) {
						deleteImage.push(image2);
						continue;
					}
					if (keepImage.length >= numberOfDockerImagesKeptLocally) {
						deleteImage.push(image2);
					} else {
						keepImage.push(image2);
					}
				}
			}
		}
		for (const image of deleteImage) {
			try {
				await executeCommand({ dockerId, command: `docker image rm -f ${image}` });
			} catch (error) {
				console.log(error);
			}
		}

		// Prune coolify managed containers
		try {
			await executeCommand({
				dockerId,
				command: `docker container prune -f --filter "label=coolify.managed=true"`
			});
		} catch (error) {}

		// Cleanup build caches
		try {
			await executeCommand({ dockerId, command: `docker builder prune -a -f` });
		} catch (error) {}
	}
}

export function persistentVolumes(id, persistentStorage, config) {
	let volumeSet = new Set();
	if (Object.keys(config).length > 0) {
		for (const [key, value] of Object.entries(config)) {
			if (value.volumes) {
				for (const volume of value.volumes) {
					if (!volume.startsWith('/')) {
						volumeSet.add(volume);
					}
				}
			}
		}
	}
	const volumesArray = Array.from(volumeSet);
	const persistentVolume =
		persistentStorage?.map((storage) => {
			return `${id}${storage.path.replace(/\//gi, '-')}:${storage.path}`;
		}) || [];

	let volumes = [...persistentVolume];
	if (volumesArray) volumes = [...volumesArray, ...volumes];
	const composeVolumes =
		(volumes.length > 0 &&
			volumes.map((volume) => {
				return {
					[`${volume.split(':')[0]}`]: {
						name: volume.split(':')[0]
					}
				};
			})) ||
		[];

	const volumeMounts = Object.assign({}, ...composeVolumes) || {};
	return { volumeMounts };
}
export function defaultComposeConfiguration(network: string): any {
	return {
		networks: [network],
		restart: 'on-failure',
		deploy: {
			restart_policy: {
				condition: 'on-failure',
				delay: '5s',
				max_attempts: 10,
				window: '120s'
			}
		}
	};
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
