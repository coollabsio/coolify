import { getTeam, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';
export const get: RequestHandler = async (request) => {
    const { teamId, status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }
    
    const { id } = request.params
    try {
        const source = await db.getSource({ id, teamId })
        return {
            body: {
                source
            }
        };
    } catch (err) {
        return err
    }

}

export const del: RequestHandler = async (request) => {
    const { status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }

    const { id } = request.params

    try {
        const source = await db.removeSource({ id })
        return {
            body: {
                source
            }
        };
    } catch (err) {
        return err
    }

}

export const post: RequestHandler<Locals, FormData> = async (request) => {
    // TODO: Do we really need groupName?
    const { teamId, status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }

    const { id } = request.params
    const name = request.body.get('name') || undefined
    const oauthId = Number(request.body.get('oauthId')) || undefined
    const groupName = request.body.get('groupName') || undefined
    const appId = request.body.get('appId') || undefined
    const appSecret = request.body.get('appSecret') || undefined

    try {
        const source = await db.addSource({ id, name, teamId, oauthId, groupName, appId, appSecret })
        return {
            body: {
                source
            }
        };
    } catch (err) {
        return err
    }
}
