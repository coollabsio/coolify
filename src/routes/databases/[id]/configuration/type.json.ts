import { asyncExecShell, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler<Locals, FormData> = async (request) => {
    const { teamId, status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }

    const types = [{ name: 'mongodb' }, { name: 'mysql' }, { name: 'couchdb' }];
    return {
        status: 200,
        body: {
            types,
        }
    }
}

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { teamId, status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }

    const { id } = request.params
    const type = request.body.get('type')

    try {
        await db.configureDatabaseType({ id, type })
        const { version } = await db.getDatabase({ id, teamId })
        asyncExecShell(`docker pull ${type}:${version}`)
        return {
            status: 201
        }
    } catch (err) {
        return err
    }
}