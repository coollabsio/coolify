import {  getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }
    const { id } = request.params

    const name = request.body.get('name') || undefined
    const domain = request.body.get('domain').toLocaleLowerCase() || undefined
    const email = request.body.get('email').toLocaleLowerCase() || undefined
    const username = request.body.get('username') || undefined

    try {
        return await db.updatePlausibleAnalyticsService({ id, domain, name, email, username })
    } catch (err) {
        return err
    }

}