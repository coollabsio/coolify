import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { teamId, status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }

    const name = request.body.get('name')
    const domain = request.body.get('domain')
    const port = request.body.get('port')
    const buildCommand = request.body.get('buildCommand')
    const startCommand = request.body.get('startCommand')
    const installCommand = request.body.get('installCommand')

    try {
        return await db.importApplication({ name, teamId, domain, port, buildCommand, startCommand, installCommand })
    } catch (err) {
        return err
    }
}


