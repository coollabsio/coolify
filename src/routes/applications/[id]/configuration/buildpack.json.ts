import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler<Locals, FormData> = async (request) => {
    const { teamId, status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }
    const { id } = request.params
    const buildPacks = [{ name: 'node' }, { name: 'static' }, { name: 'docker' }];
    const application = await db.getApplication({ id, teamId });
    return {
        status: 200,
        body: {
            buildPacks,
            type: application.gitSource.type,
            projectId: application.projectId,
            repository: application.repository,
            branch: application.branch,
            apiUrl: application.gitSource.apiUrl
        }
    }
}

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { id } = request.params
    const buildPack = request.body.get('buildPack') || null
    try {
        return await db.configureBuildPack({ id, buildPack })
    } catch (err) {
        return err
    }
}

