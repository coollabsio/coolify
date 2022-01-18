import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { supportedDatabaseTypesAndVersions } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler<Locals, FormData> = async (request) => {
    const { teamId, status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }
    return {
        status: 200,
        body: {
            types: supportedDatabaseTypesAndVersions
        }
    }
}

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { teamId, status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }

    const { id } = request.params
    const type = request.body.get('type') || undefined

    try {
        await db.configureDatabaseType({ id, type })
        return {
            status: 201
        }
    } catch (err) {
        return err
    }
}