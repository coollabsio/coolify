import { getUserDetails, uniqueName } from '$lib/common';
import * as db from '$lib/database';
import { PrismaErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler<Locals> = async (event) => {
	const { userId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body }

	const data = await event.request.formData();
	const name = data.get('name')

	try {
		return await db.newTeam({ name, userId })
	} catch (err) {
		return err
	}
}


