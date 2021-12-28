import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler<Locals, FormData> = async (request) => {
    const { id } = request.params
    const repository = request.query.get('repository') || null
    const branch = request.query.get('branch') || null

    try {
        return await db.isBranchAlreadyUsed({ repository, branch, id })
    } catch (err) {
        return err
    }
}

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { id } = request.params
    const repository = request.body.get('repository') || null
    const branch = request.body.get('branch') || null
    const projectId = Number(request.body.get('projectId')) || null
    const webhookToken = request.body.get('webhookToken')
    try {
        return await db.configureGitRepository({ id, repository, branch, projectId, webhookToken })

    } catch (err) {
        return err
    }
}