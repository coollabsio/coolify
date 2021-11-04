import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler<Locals, FormData> = async (request) => {
	const name = request.body.get('name') || null
	return await db.newApplication({ name })
}


