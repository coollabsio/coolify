import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (request) => {
    const gitSourceId = request.query.get('gitSourceId')
    const installation_id = request.query.get('installation_id')

    const dbresponse = await db.addInstallation({ gitSourceId, installation_id })
    if (dbresponse.status !== 201) {
        return {
            ...dbresponse
        }
    }
    return {
        status: 302,
        headers: { Location: `/sources/${gitSourceId}` }
    }
}