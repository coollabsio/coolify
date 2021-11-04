import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler<Locals, FormData> = async (request) => {
    const repository = request.query.get('repository') || null
    const branch = request.query.get('branch') || null
    return await db.isBranchAlreadyUsed({ repository, branch })
}

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { id } = request.params
    const repository = request.body.get('repository') || null
    const branch = request.body.get('branch') || null
    return await db.configureRepository({ id, repository, branch })
}