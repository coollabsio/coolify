import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const del: RequestHandler<Locals, FormData> = async (request) => {
    const { teamId, status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }
    const { id } = request.params
    try {
        await db.removeService({ id })
        return {
            status: 200
        }
    } catch (error) {
        console.error(error)
        return {
            status: 500
        }
    }

}