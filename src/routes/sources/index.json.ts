import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async () => {
    return {
        body: {
            sources: await db.listSources()
        }
    };
}