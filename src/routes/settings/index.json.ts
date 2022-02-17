import { getDomain, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { listSettings, ErrorHandler } from '$lib/database';
import {
	configureCoolifyProxyOff,
	configureCoolifyProxyOn,
	forceSSLOnApplication,
	reloadHaproxy,
	removeWwwRedirection,
	setWwwRedirection,
	startCoolifyProxy
} from '$lib/haproxy';
import { letsEncrypt } from '$lib/letsencrypt';
import type { RequestHandler } from '@sveltejs/kit';
import dns from 'dns/promises';

export const get: RequestHandler = async (event) => {
	const { status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	try {
		const settings = await listSettings();
		return {
			status: 200,
			body: {
				settings
			}
		};
	} catch (error) {
		return ErrorHandler(error);
	}
};

export const del: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (teamId !== '0')
		return {
			status: 401,
			body: {
				message: 'You do not have permission to do this. \nAsk an admin to modify your permissions.'
			}
		};
	if (status === 401) return { status, body };

	const { fqdn } = await event.request.json();
	const ip = await dns.resolve(event.url.hostname);
	try {
		const domain = getDomain(fqdn);
		await db.prisma.setting.update({ where: { fqdn }, data: { fqdn: null } });
		await configureCoolifyProxyOff(fqdn);
		await removeWwwRedirection(domain);
		return {
			status: 200,
			body: {
				message: 'Domain removed',
				redirect: `http://${ip[0]}:3000/settings`
			}
		};
	} catch (error) {
		return ErrorHandler(error);
	}
};
export const post: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (teamId !== '0')
		return {
			status: 401,
			body: {
				message: 'You do not have permission to do this. \nAsk an admin to modify your permissions.'
			}
		};
	if (status === 401) return { status, body };

	const { fqdn, isRegistrationEnabled, dualCerts } = await event.request.json();
	try {
		const {
			id,
			fqdn: oldFqdn,
			isRegistrationEnabled: oldIsRegistrationEnabled,
			dualCerts: oldDualCerts
		} = await db.listSettings();
		if (oldIsRegistrationEnabled !== isRegistrationEnabled) {
			await db.prisma.setting.update({ where: { id }, data: { isRegistrationEnabled } });
		}
		if (oldDualCerts !== dualCerts) {
			await db.prisma.setting.update({ where: { id }, data: { dualCerts } });
		}
		if (oldFqdn && oldFqdn !== fqdn) {
			if (oldFqdn) {
				const oldDomain = getDomain(oldFqdn);
				await configureCoolifyProxyOff(oldFqdn);
				await removeWwwRedirection(oldDomain);
			}
		}
		if (fqdn) {
			await startCoolifyProxy('/var/run/docker.sock');
			const domain = getDomain(fqdn);
			const isHttps = fqdn.startsWith('https://');
			if (domain) {
				await configureCoolifyProxyOn(fqdn);
				await setWwwRedirection(fqdn);
				if (isHttps) {
					await letsEncrypt({ domain, isCoolify: true });
					await forceSSLOnApplication({ domain });
					await reloadHaproxy('/var/run/docker.sock');
				}
			}

			await db.prisma.setting.update({ where: { id }, data: { fqdn } });
			await db.prisma.destinationDocker.updateMany({
				where: { engine: '/var/run/docker.sock' },
				data: { isCoolifyProxyUsed: true }
			});
		}

		return {
			status: 201
		};
	} catch (error) {
		return ErrorHandler(error);
	}
};
