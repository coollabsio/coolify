import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { teamId, status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }

    const name = request.body.get('name') || undefined
    const domain = request.body.get('domain').toLocaleLowerCase() || undefined
    const port = Number(request.body.get('port')) || undefined
    const buildCommand = request.body.get('buildCommand') || undefined
    const startCommand = request.body.get('startCommand') || undefined
    const installCommand = request.body.get('installCommand') || undefined

    try {
        return await db.importApplication({ name, teamId, domain, port, buildCommand, startCommand, installCommand })
    } catch (err) {
        return err
    }
}


