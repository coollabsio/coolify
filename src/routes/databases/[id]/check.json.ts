import { asyncExecShell, getHost, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }

    const domain = request.body.get('domain')
    try {
        return db.isDomainConfigured({ domain })
    } catch (err) {
        return err
    }

}