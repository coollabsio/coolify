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
    const { id } = request.params
    return {
        body: {
            source: await db.removeSource({ id })
        }
    };
}

export const post: RequestHandler<Locals, FormData> = async (request) => {
    // TODO: Do we really need groupName?
    const { id } = request.params
    const name = request.body.get('name')
    const groupName = request.body.get('groupName') || null
    const appId = request.body.get('appId')
    const appSecret = request.body.get('appSecret')
    return {
        body: {
            source: await db.addSource({ id, name, groupName, appId, appSecret })
        }
    };
}
