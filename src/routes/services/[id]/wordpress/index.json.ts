import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }
    const { id } = request.params

    const name = request.body.get('name') || undefined
    const fqdn = request.body.get('fqdn')?.toLocaleLowerCase() || undefined
    const extraConfig = request.body.get('extraConfig') || undefined
    const mysqlDatabase = request.body.get('mysqlDatabase') || undefined

    try {
        return await db.updateWordpress({ id, fqdn, name, extraConfig, mysqlDatabase })
    } catch (err) {
        return err
    }

}