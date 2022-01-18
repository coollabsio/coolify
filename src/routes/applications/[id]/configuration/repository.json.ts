import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler<Locals, FormData> = async (request) => {
    const { id } = request.params
    const repository = request.url.searchParams.get('repository').toLocaleLowerCase() || undefined
    const branch = request.url.searchParams.get('branch').toLocaleLowerCase() || undefined

    try {
        return await db.isBranchAlreadyUsed({ repository, branch, id })
    } catch (err) {
        return err
    }
}

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { id } = request.params
    const repository = request.body.get('repository').toLocaleLowerCase() || undefined
    const branch = request.body.get('branch').toLocaleLowerCase() || undefined
    const projectId = Number(request.body.get('projectId')) || undefined
    const webhookToken = request.body.get('webhookToken')
    try {
        return await db.configureGitRepository({ id, repository, branch, projectId, webhookToken })

    } catch (err) {
        return err
    }
}