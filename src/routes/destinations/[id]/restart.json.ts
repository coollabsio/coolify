import { getUserDetails } from '$lib/common';
import { ErrorHandler } from '$lib/database';
import * as db from '$lib/database';
import {
	startCoolifyProxy,
	startTraefikProxy,
	stopCoolifyProxy,
	stopTraefikProxy
} from '$lib/haproxy';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { engine } = await event.request.json();

	try {
		const settings = await db.prisma.setting.findFirst({});
		if (settings?.isTraefikUsed) {
			await stopTraefikProxy(engine);
			await startTraefikProxy(engine);
		} else {
			await stopCoolifyProxy(engine);
			await startCoolifyProxy(engine);
		}
		await db.setDestinationSettings({ engine, isCoolifyProxyUsed: true });

		return {
			status: 200
		};
	} catch (error) {
		return ErrorHandler(error);
	}
};
