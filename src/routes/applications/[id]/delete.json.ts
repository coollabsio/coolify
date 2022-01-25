import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { PrismaErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const del: RequestHandler<Locals> = async (event) => {
    const { teamId, status, body } = await getUserDetails(event);
    if (status === 401) return { status, body }

    const { id } = event.params
    try {
        await db.removeApplication({ id, teamId })
        return {
            status: 200
        }
    } catch (error) {
        return PrismaErrorHandler(error)

    }

}