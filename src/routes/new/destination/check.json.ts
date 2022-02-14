import { getUserDetails } from '$lib/common';
import { isDockerNetworkExists, ErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { network } = await event.request.json();
	try {
		const found = await isDockerNetworkExists({ network });
		if (found) {
			throw {
				error: `Network ${network} already configured for another team!`
			};
		}
		return {
			status: 200
		};
	} catch (error) {
		return ErrorHandler(error);
	}
};
