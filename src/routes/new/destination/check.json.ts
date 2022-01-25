import { getUserDetails } from '$lib/common';
import { isDockerNetworkExists, PrismaErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler<Locals> = async (event) => {
    const { teamId, status, body } = await getUserDetails(event);
    if (status === 401) return { status, body }

    const { network } = await event.request.json()
    try {
        const found = await isDockerNetworkExists({ network })
        return {
            status: found ? 500 : 200,
            body: {
                error: found && 'Network already configured on the destination.'
            }
        }
    } catch (error) {
        return PrismaErrorHandler(error)
    }
}