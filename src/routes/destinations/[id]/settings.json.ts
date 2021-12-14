import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }

    const engine = request.body.get('engine')
    const isCoolifyProxyUsed = request.body.get('isCoolifyProxyUsed') === 'true' ? true : false

    try {
        return await db.setDestinationSettings({ engine, isCoolifyProxyUsed })
    } catch (err) {
        return err
    }

}