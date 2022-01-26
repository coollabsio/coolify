import { getUserDetails } from '$lib/common';
import { isDockerNetworkExists, PrismaErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler<Locals> = async (event) => {
    const { teamId, status, body } = await getUserDetails(event);
    if (status === 401) return { status, body }

    const { network } = await event.request.json()
    try {
        const found = await isDockerNetworkExists({ network })
        if (found) {
            throw {
                error: `Network ${network} already configured on the destination.`,
            }
        }
        return {
            status: 200
        }
    } catch (error) {
        return PrismaErrorHandler(error)
    }
}