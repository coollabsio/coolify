import { selectTeam } from '$lib/common';
import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (request) => {
    const teamId = selectTeam(request)
    return {
        body: {
            applications: await db.listApplications(teamId)
        }
    };
}