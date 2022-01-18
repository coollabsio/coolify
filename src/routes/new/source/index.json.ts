import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { teamId, status, body } = await getUserDetails(request)
    if (status === 401) return { status, body }

    const name = request.body.get('name') || undefined
    const type = request.body.get('type') || undefined
    const htmlUrl = request.body.get('htmlUrl') || undefined
    const apiUrl = request.body.get('apiUrl') || undefined
    const organization = request.body.get('organization') || undefined

    try {
        return await db.newSource({ name, teamId, type, htmlUrl, apiUrl, organization })
    } catch (err) {
        return err
    }

}


