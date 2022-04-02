import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler = async (event) => {
	const { id } = event.params;
	let { deployKeyId } = await event.request.json();

	deployKeyId = Number(deployKeyId);

	try {
		await db.updateDeployKey({ id, deployKeyId });
		return { status: 201 };
	} catch (error) {
		return ErrorHandler(error);
	}
};
