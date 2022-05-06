import { dev } from '$app/env';
import { checkDomainsIsValidInDNS, getDomain, getUserDetails, isDNSValid } from '$lib/common';
import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';
import { promises as dns } from 'dns';
import getPort from 'get-port';
import { t } from '$lib/translations';

export const get: RequestHandler = async (event) => {
	const { status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };
	const domain = event.url.searchParams.get('domain');
	if (!domain) {
		return {
			status: 500,
			body: {
				message: t.get('application.domain_required')
			}
		};
	}
	try {
		await isDNSValid(event, domain);
		return {
			status: 200
		};
	} catch (error) {
		return ErrorHandler(error);
	}
};

export const post: RequestHandler = async (event) => {
	const { status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { id } = event.params;
	let { exposePort, fqdn, forceSave, dualCerts } = await event.request.json();
	fqdn = fqdn.toLowerCase();

	try {
		const { isDNSCheckEnabled } = await db.prisma.setting.findFirst({});
		const found = await db.isDomainConfigured({ id, fqdn });
		if (found) {
			throw {
				message: t.get('application.domain_already_in_use', {
					domain: getDomain(fqdn).replace('www.', '')
				})
			};
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

		if (isDNSCheckEnabled && !forceSave) {
			return await checkDomainsIsValidInDNS({ event, fqdn, dualCerts });
		}

		return {
			status: 200
		};
	} catch (error) {
		return ErrorHandler(error);
	}
};
