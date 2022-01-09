import { asyncExecShell, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { dockerInstance } from '$lib/docker';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler<Locals, FormData> = async (request) => {
    const { teamId, status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }

    const { id } = request.params
    const { type } = await db.getDatabase({ id, teamId })
    const versions = []
    if (type === 'mysql') {
        versions.push({ name: '8.0.27', version: '8.0.27' })
        versions.push({ name: '5.7.36', version: '5.7.36' })
    } else if (type === 'mongodb') {
        versions.push({ name: '5.0.5', version: '5.0.5' })
        versions.push({ name: '4.4.11', version: '4.4.11' })
        versions.push({ name: '4.2.18', version: '4.2.18' })
        versions.push({ name: '4.0.27', version: '4.0.27' })
    }

    return {
        status: 200,
        body: {
            versions,
        }
    }
}

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { teamId, status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }

    const { id } = request.params
    const version = request.body.get('version')
    console.log(version)

    try {
        await db.updateDatabase({ id, version })
        return {
            status: 201
        }
    } catch (err) {
        return err
    }
}