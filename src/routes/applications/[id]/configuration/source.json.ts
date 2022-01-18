import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { id } = request.params
    const gitSourceId = request.body.get('gitSourceId') || undefined
    try {
        return await db.configureGitsource({ id, gitSourceId })
    } catch(err) {
        return err
    }
}


