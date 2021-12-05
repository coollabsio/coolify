import { getTeam } from '$lib/common';
import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (request) => {
    const teamId = getTeam(request)
    
    try {
        return {
            body: {
                destinations: await db.listDestinations(teamId)
            }
        };
    } catch (err) {
        return err
    }

}