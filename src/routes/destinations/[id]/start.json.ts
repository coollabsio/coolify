import { getUserDetails } from '$lib/common';
import { PrismaErrorHandler } from '$lib/database';
import { startCoolifyProxy, stopCoolifyProxy } from '$lib/haproxy';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { engine } = await event.request.json();

	try {
		await startCoolifyProxy(engine);
		return {
			status: 200
		};
	} catch (error) {
		await stopCoolifyProxy(engine);
		return PrismaErrorHandler(error);
	}
};
