import {  getUserDetails } from '$lib/common';
import { startCoolifyProxy, stopCoolifyProxy } from '$lib/haproxy';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { teamId, status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }

    const engine = request.body.get('engine')
    try {
        await startCoolifyProxy(engine)
    } catch (error) {
        await stopCoolifyProxy(engine)
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
