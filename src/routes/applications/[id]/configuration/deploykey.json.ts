import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { id } = request.params
    const deployKeyId = Number(request.body.get('deployKeyId')) || null
    try {
        return await db.updateDeployKey({ id, deployKeyId })
    } catch (err) {
        return err
    }
}


