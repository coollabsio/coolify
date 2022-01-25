import { getUserDetails } from '$lib/common';
import { startCoolifyProxy, stopCoolifyProxy } from '$lib/haproxy';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler<Locals> = async (event) => {
    const { teamId, status, body } = await getUserDetails(event);
    if (status === 401) return { status, body }

    const { engine } = await event.request.json()

    try {
        await startCoolifyProxy(engine)
        return {
            status: 200,
        };
    } catch (error) {
        await stopCoolifyProxy(engine)
        return {
            status: 500,
            body: {
                message: error.message || error
            }
        }
    }

}
