import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { id } = request.params
    const gitSourceId = request.body.get('gitSourceId') || null
    return await db.configureGitsource({ id, gitSourceId })
}


