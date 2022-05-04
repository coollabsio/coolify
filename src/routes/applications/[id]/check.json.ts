import { dev } from '$app/env';
import { getDomain, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';
import { promises as dns } from 'dns';
import getPort from 'get-port';
import { t } from '$lib/translations';

export const post: RequestHandler = async (event) => {
	const { status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { id } = event.params;
	let { exposePort, fqdn, forceSave } = await event.request.json();
	fqdn = fqdn.toLowerCase();

	try {
		const domain = getDomain(fqdn);
		const found = await db.isDomainConfigured({ id, fqdn });
		if (found) {
			throw {
				message: t.get('application.domain_already_in_use', {
					domain: getDomain(fqdn).replace('www.', '')
				})
			};
		}
		if (!dev && !forceSave) {
			let ip = [];
			let localIp = [];
			dns.setServers(['1.1.1.1', '8.8.8.8']);

			try {
				localIp = await dns.resolve4(event.url.hostname);
			} catch (error) {}
			try {
				ip = await dns.resolve4(domain);
			} catch (error) {}

			if (localIp?.length > 0) {
				if (ip?.length === 0 || !ip.includes(localIp[0])) {
					throw {
						message: t.get('application.dns_not_set_error', { domain: domain })
					};
				}
			}
		}

		if (exposePort) {
			exposePort = Number(exposePort);

			if (exposePort < 1024 || exposePort > 65535) {
				throw { message: `Expose Port needs to be between 1024 and 65535.` };
			}

			const publicPort = await getPort({ port: exposePort });
			if (publicPort !== exposePort) {
				throw { message: `Port ${exposePort} is already in use.` };
			}
		}

		return {
			status: 200
		};
	} catch (error) {
		return ErrorHandler(error);
	}
};
