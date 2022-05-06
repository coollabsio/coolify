import { dev } from '$app/env';
import { checkDomainsIsValidInDNS, getDomain, getUserDetails, isDNSValid } from '$lib/common';
import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';
import { t } from '$lib/translations';
import type { RequestHandler } from '@sveltejs/kit';

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

	let { fqdn, forceSave, dualCerts, isDNSCheckEnabled } = await event.request.json();
	if (fqdn) fqdn = fqdn.toLowerCase();
	try {
		const found = await db.isDomainConfigured({ id, fqdn });
		console.log(found);
		if (found) {
			throw {
				message: t.get('application.domain_already_in_use', {
					domain: getDomain(fqdn).replace('www.', '')
				})
			};
		}
		if (isDNSCheckEnabled && !dev && !forceSave) {
			return await checkDomainsIsValidInDNS({ event, fqdn, dualCerts });
		}
		return {
			status: 200
		};
	} catch (error) {
		return ErrorHandler(error);
	}
};
