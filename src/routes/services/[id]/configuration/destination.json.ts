import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { id } = request.params
    const destinationId = request.body.get('destinationId') || undefined
    try {
        return await db.configureDestinationForService({ id, destinationId })
    } catch(err) {
        return err
    }
}


