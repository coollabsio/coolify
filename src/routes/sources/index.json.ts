import { getTeam, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (request) => {
    const { teamId, status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }

    try {
        const sources = await db.listSources(teamId)
        return {
            body: {
                sources
            }
        };
    } catch (err) {
        return err
    }

}