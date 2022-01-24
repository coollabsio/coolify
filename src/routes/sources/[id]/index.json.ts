import { getTeam, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { PrismaErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';
export const get: RequestHandler = async (request) => {
    const { teamId, status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }

    const { id } = request.params
    try {
        const source = await db.getSource({ id, teamId })
        return {
            status: 200,
            body: {
                source
            }
        };
    } catch (error) {
        throw PrismaErrorHandler(error)
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

export const post: RequestHandler<Locals> = async (event) => {
    const { teamId, status, body } = await getUserDetails(event);
    if (status === 401) return { status, body }
    const { id } = event.params

    try {
        let { oauthId, groupName, appId, appSecret } = await event.request.json()

        oauthId = Number(oauthId)

        return await db.addSource({ id, teamId, oauthId, groupName, appId, appSecret })
    } catch (error) {
        return PrismaErrorHandler(error)
    }
}
