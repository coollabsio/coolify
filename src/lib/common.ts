import child from 'child_process';
import util from 'util';
import { dev } from '$app/env';
import * as Sentry from '@sentry/node';
import { uniqueNamesGenerator, adjectives, colors, animals } from 'unique-names-generator';
import type { Config } from 'unique-names-generator';
import { promises as dns } from 'dns';
import { isIP } from 'is-ip';

import * as db from '$lib/database';
import { buildLogQueue } from './queues';

import { version as currentVersion } from '../../package.json';
import dayjs from 'dayjs';
import Cookie from 'cookie';
import os from 'os';
import type { RequestEvent } from '@sveltejs/kit/types/internal';
import type { Job } from 'bullmq';
import { t } from './translations';

try {
	if (!dev) {
		// Sentry.init({
		// 	dsn: process.env['COOLIFY_SENTRY_DSN'],
		// 	tracesSampleRate: 0,
		// 	environment: 'production',
		// 	debug: true,
		// 	release: currentVersion,
		// 	initialScope: {
		// 		tags: {
		// 			appId: process.env['COOLIFY_APP_ID'],
		// 			'os.arch': getOsArch(),
		// 			'os.platform': os.platform(),
		// 			'os.release': os.release()
		// 		}
		// 	}
		// });
	}
} catch (err) {
	console.log('Could not initialize Sentry, no worries.');
}

const customConfig: Config = {
	dictionaries: [adjectives, colors, animals],
	style: 'capital',
	separator: ' ',
	length: 3
};

export const version = currentVersion;
export const asyncExecShell = util.promisify(child.exec);
export const asyncSleep = (delay: number): Promise<unknown> =>
	new Promise((resolve) => setTimeout(resolve, delay));
export const sentry = Sentry;

export const uniqueName = (): string => uniqueNamesGenerator(customConfig);

export const saveBuildLog = async ({
	line,
	buildId,
	applicationId
}: {
	line: string;
	buildId: string;
	applicationId: string;
}): Promise<Job> => {
	if (line && typeof line === 'string' && line.includes('ghs_')) {
		const regex = /ghs_.*@/g;
		line = line.replace(regex, '<SENSITIVE_DATA_DELETED>@');
	}
	const addTimestamp = `${generateTimestamp()} ${line}`;
	return await buildLogQueue.add(buildId, { buildId, line: addTimestamp, applicationId });
};

export const getTeam = (event: RequestEvent): string | null => {
	const cookies = Cookie.parse(event.request.headers.get('cookie'));
	if (cookies?.teamId) {
		return cookies.teamId;
	} else if (event.locals.session.data?.teamId) {
		return event.locals.session.data.teamId;
	}
	return null;
};

export const getUserDetails = async (
	event: RequestEvent,
	isAdminRequired = true
): Promise<{
	teamId: string;
	userId: string;
	permission: string;
	status: number;
	body: { message: string };
}> => {
	const teamId = getTeam(event);
	const userId = event?.locals?.session?.data?.userId || null;
	let permission = 'read';
	if (teamId && userId) {
		try {
			const data = await db.prisma.permission.findFirst({
				where: { teamId, userId },
				select: { permission: true },
				rejectOnNotFound: true
			});
			if (data.permission) permission = data.permission;
		} catch (error) {
			console.log(error);
		}
	}

	const payload = {
		teamId,
		userId,
		permission,
		status: 200,
		body: {
			message: 'OK'
		}
	};

	if (isAdminRequired && permission !== 'admin' && permission !== 'owner') {
		payload.status = 401;
		payload.body.message =
			'You do not have permission to do this. \nAsk an admin to modify your permissions.';
	}

	return payload;
};

export function getEngine(engine: string): string {
	return engine === '/var/run/docker.sock' ? 'unix:///var/run/docker.sock' : engine;
}

export async function removeContainer(id: string, engine: string): Promise<void> {
	const host = getEngine(engine);
	try {
		const { stdout } = await asyncExecShell(
			`DOCKER_HOST=${host} docker inspect --format '{{json .State}}' ${id}`
		);
		if (JSON.parse(stdout).Running) {
			await asyncExecShell(`DOCKER_HOST=${host} docker stop -t 0 ${id}`);
			await asyncExecShell(`DOCKER_HOST=${host} docker rm ${id}`);
		}
	} catch (error) {
		console.log(error);
		throw error;
	}
}

export const removeDestinationDocker = async ({
	id,
	engine
}: {
	id: string;
	engine: string;
}): Promise<void> => {
	return await removeContainer(id, engine);
};

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

export function generateTimestamp(): string {
	return `${dayjs().format('HH:mm:ss.SSS')} `;
}

export function getDomain(domain: string): string {
	return domain?.replace('https://', '').replace('http://', '');
}

export function getOsArch() {
	return os.arch();
}

export async function isDNSValid(event: any, domain: string): Promise<any> {
	let resolves = [];
	try {
		if (isIP(event.url.hostname)) {
			resolves = [event.url.hostname];
		} else {
			resolves = await dns.resolve4(event.url.hostname);
		}
	} catch (error) {
		throw {
			message: t.get('application.dns_not_set_error', { domain })
		};
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
		throw {
			message: t.get('application.domain_not_valid')
		};
	}
}

export async function checkDomainsIsValidInDNS({ event, fqdn, dualCerts }): Promise<any> {
	const domain = getDomain(fqdn);
	const domainDualCert = domain.includes('www.') ? domain.replace('www.', '') : `www.${domain}`;
	dns.setServers(['1.1.1.1', '8.8.8.8']);
	let resolves = [];
	try {
		if (isIP(event.url.hostname)) {
			resolves = [event.url.hostname];
		} else {
			resolves = await dns.resolve4(event.url.hostname);
		}
	} catch (error) {
		throw {
			message: t.get('application.dns_not_set_error', { domain })
		};
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
			throw false;
		} catch (error) {
			throw {
				message: t.get('application.dns_not_set_error', { domain })
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
			throw false;
		} catch (error) {
			throw {
				message: t.get('application.dns_not_set_error', { domain })
			};
		}
	}
}
