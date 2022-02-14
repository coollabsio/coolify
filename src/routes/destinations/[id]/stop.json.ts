import { getUserDetails } from '$lib/common';
import { ErrorHandler } from '$lib/database';
import { stopCoolifyProxy } from '$lib/haproxy';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { engine } = await event.request.json();
	try {
		await stopCoolifyProxy(engine);
		return {
			status: 200
		};
	} catch (error) {
		return ErrorHandler(error);
	}
};
