import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (request) => {
    const { teamId, status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }
   
    const { id } = request.params
    const database = await db.getDatabase({ id, teamId })
    return {
        body: {
            database
        }
    };

}


export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { teamId, status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }

    const { id } = request.params
    const name = request.body.get('name') || null

    try {
        return db.updateDatabase({ id, name })
    } catch (err) {
        return err
    }

}