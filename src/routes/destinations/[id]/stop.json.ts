import { getUserDetails } from '$lib/common';
import { stopCoolifyProxy } from '$lib/haproxy';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { teamId, status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }

    const engine = request.body.get('engine')
    try {
        await stopCoolifyProxy(engine)

    } catch (error) {
        return {
            status: 500,
            body: {
                message: error.message || error
            }
        }
    }
    return {
        status: 200,
    };
}
