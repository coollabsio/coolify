import { getTeam, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';
export const get: RequestHandler = async (request) => {
    const teamId = getTeam(request)
    const { id } = request.params
    return {
        body: {
            source: await db.getSource({ id, teamId })
        }
    };
}

export const del: RequestHandler = async (request) => {
    const { status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }

    const { id } = request.params
    return {
        body: {
            source: await db.removeSource({ id })
        }
    };
}

export const post: RequestHandler<Locals, FormData> = async (request) => {
    // TODO: Do we really need groupName?
    const { status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }
    
    const teamId = getTeam(request)
    const { id } = request.params
    const name = request.body.get('name')
    const oauthId = Number(request.body.get('oauthId'))
    const groupName = request.body.get('groupName') || null
    const appId = request.body.get('appId')
    const appSecret = request.body.get('appSecret')
    return {
        body: {
            source: await db.addSource({ id, name, teamId, oauthId, groupName, appId, appSecret })
        }
    };
}
