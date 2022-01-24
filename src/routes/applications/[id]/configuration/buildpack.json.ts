import { getUserDetails } from '$lib/common';
import { buildPacks } from '$lib/buildPacks/common';
import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';
import { PrismaErrorHandler } from '$lib/database';

export const get: RequestHandler<Locals> = async (event) => {
    const { teamId, status, body } = await getUserDetails(event);
    if (status === 401) return { status, body }

    const { id } = event.params
    try {
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
    } catch (error) {
        return PrismaErrorHandler(error)
    }

}

export const post: RequestHandler<Locals> = async (event) => {
    const { teamId, status, body } = await getUserDetails(event);
    if (status === 401) return { status, body }
    const { id } = event.params
    const { buildPack } = await event.request.json()
    try {
        await db.configureBuildPack({ id, buildPack })
        return { status: 201 }
    } catch (error) {
        return PrismaErrorHandler(error)
    }
}

