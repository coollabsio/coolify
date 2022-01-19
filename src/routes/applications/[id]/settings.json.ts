import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { letsEncrypt } from '$lib/letsencrypt';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { teamId, status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }

    const { id } = request.params
    const debug = request.body.get('debug') === 'true' ? true : false
    const previews = request.body.get('previews') === 'true' ? true : false

    try {
        await db.setApplicationSettings({ id, debug, previews })

        return { status: 200 }
    } catch (err) {
        return {
            status: 500,
            body: {
                message: err.message || err
            }
        }
    }

}