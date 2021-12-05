import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { id } = request.params
    try {
        return await db.generateSshKey({ id });
    } catch(err) {
        return err
    }
}


