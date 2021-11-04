import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';
export const get: RequestHandler = async (request) => {
    const { id } = request.params
    return {
        body: {
            source: await db.getSource({ id })
        }
    };
}

export const del: RequestHandler = async (request) => {
    const { id } = JSON.parse(request.body.toString())
    return {
        body: {
            source: await db.removeSource({ id })
        }
    };
}

