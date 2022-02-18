import { getDomain, getUserDetails } from '$lib/common';
import { ErrorHandler } from '$lib/database';
import * as db from '$lib/database';
import {
	configureCoolifyProxyOn,
	forceSSLOnApplication,
	setWwwRedirection,
	startCoolifyProxy,
	stopCoolifyProxy
} from '$lib/haproxy';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { engine, fqdn } = await event.request.json();

	try {
		const domain = getDomain(fqdn);
		await stopCoolifyProxy(engine);
		await startCoolifyProxy(engine);
		await db.setDestinationSettings({ engine, isCoolifyProxyUsed: true });
		await configureCoolifyProxyOn(fqdn);
		await setWwwRedirection(fqdn);
		const isHttps = fqdn.startsWith('https://');
		if (isHttps) await forceSSLOnApplication(domain);
		return {
			status: 200
		};
	} catch (error) {
		return ErrorHandler(error);
	}
};
