import { asyncExecShell, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { dockerInstance } from '$lib/docker';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { teamId, status, body } = await getUserDetails(request)
    if (status === 401) return { status, body }

    const name = request.body.get('name') || undefined
    const engine = request.body.get('engine') || undefined
    const network = request.body.get('network') || undefined
    const isCoolifyProxyUsed = request.body.get('isCoolifyProxyUsed') === 'true' ? true : false

    try {
        const { body } = await db.newDestination({ name, teamId, engine, network, isCoolifyProxyUsed })

        return { status: 200, body: { message: 'Destination created', id: body.id } }
    } catch (err) {
        return err
    }
}

