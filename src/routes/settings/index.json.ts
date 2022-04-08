import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { listSettings, ErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';
import { promises as dns } from 'dns';

export const get: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };
	if (teamId !== '0') return { status: 401, body: { message: 'You are not an admin.' } };
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
	let ip;
	try {
		ip = await dns.resolve(fqdn);
	} catch (error) {
		// Do not care.
	}
	try {
		await db.prisma.setting.update({ where: { fqdn }, data: { fqdn: null } });
		return {
			status: 200,
			body: {
				message: 'Domain removed',
				redirect: ip ? `http://${ip[0]}:3000/settings` : undefined
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

	const { fqdn, isRegistrationEnabled, dualCerts, minPort, maxPort } = await event.request.json();
	try {
		const { id } = await db.listSettings();
		await db.prisma.setting.update({ where: { id }, data: { isRegistrationEnabled, dualCerts } });
		if (fqdn) {
			await db.prisma.setting.update({ where: { id }, data: { fqdn } });
		}
		if (minPort && maxPort) {
			await db.prisma.setting.update({ where: { id }, data: { minPort, maxPort } });
		}
		return {
			status: 201
		};
	} catch (error) {
		return ErrorHandler(error);
	}
};
