import { selectTeam } from '$lib/common';
import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const teamId = selectTeam(request)
    const name = request.body.get('name') || null
    const isSwarm = request.body.get('isSwarm') || false
    const engine = request.body.get('engine') || null
    const network = request.body.get('network') || null
    return await db.newDestination({ name, teamId, isSwarm, engine, network })
}


