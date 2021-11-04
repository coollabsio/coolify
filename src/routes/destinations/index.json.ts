import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async () => {
    return {
        body: {
            destinations: await db.listDestinations()
        }
    };
}