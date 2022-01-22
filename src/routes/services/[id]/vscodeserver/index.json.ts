import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }
    const { id } = request.params

    const name = request.body.get('name') || undefined
    const fqdn = request.body.get('fqdn')?.toLocaleLowerCase() || undefined

    try {
        return await db.updateVsCodeServer({ id, fqdn, name })
    } catch (err) {
        return err
    }

}