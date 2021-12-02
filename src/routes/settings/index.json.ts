import { getTeam, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (request) => {
    const { teamId, status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }

    return {
        body: {
            settings: await db.prisma.setting.findMany({})
        }
    };
}


export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { teamId, status, body } = await getUserDetails(request);
    if (teamId !== '0') return { status: 401, body: { message: 'You do not have permission to do this. \nAsk an admin to modify your permissions.' } }
    if (status === 401) return { status, body }

    const name = request.body.get('name') || null
    const value = request.body.get('value') || null
    try {
        await db.prisma.setting.update({ where: { name }, data: { value } })

    } catch (error) {
        console.log(error)
    }

    return {
        status: 200,
    }

}