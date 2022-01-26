import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { PrismaErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler<Locals> = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };
	const { id } = event.params;

	const { destinationId } = await event.request.json();

	try {
		await db.configureDestinationForService({ id, destinationId });
		return { status: 201 };
	} catch (error) {
		return PrismaErrorHandler(error);
	}
};
