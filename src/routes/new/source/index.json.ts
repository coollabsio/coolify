import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler<Locals, FormData> = async (request) => {
	const { teamId, status, body } = await getUserDetails(request)
	if (status === 401) return { status, body }

    const name = request.body.get('name') || null
    const type = request.body.get('type') || null
    const htmlUrl = request.body.get('htmlUrl') || null
    const apiUrl = request.body.get('apiUrl') || null
    const organization = request.body.get('organization') || null

    return await db.newSource({ name, teamId, type, htmlUrl, apiUrl, organization })
}


