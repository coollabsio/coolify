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
	const settings = await db.prisma.setting.findFirst({});

	try {
		if (settings?.isTraefikUsed) {
			await startTraefikProxy(engine);
		} else {
			await startCoolifyProxy(engine);
		}

		return {
			status: 200
		};
	} catch (error) {
		if (settings?.isTraefikUsed) {
			await stopTraefikProxy(engine);
		} else {
			await stopCoolifyProxy(engine);
		}
		return ErrorHandler(error);
	}
};
