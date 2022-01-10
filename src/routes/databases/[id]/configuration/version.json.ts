import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { supportedDatabaseTypesAndVersions } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler<Locals, FormData> = async (request) => {
    const { teamId, status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }

    const { id } = request.params
    const { type } = await db.getDatabase({ id, teamId })

    return {
        status: 200,
        body: {
            versions: supportedDatabaseTypesAndVersions.find(name => name.name === type).versions
        }
    }
}

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { teamId, status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }

    const { id } = request.params
    const version = request.body.get('version')

    try {
        await db.updateDatabase({ id, version })
        return {
            status: 201
        }
    } catch (err) {
        return err
    }
}