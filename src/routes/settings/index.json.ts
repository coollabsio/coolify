import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { listSettings, PrismaErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (request) => {
    const { status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }

    try {
        return {
            body: {
                settings: await listSettings()
            }
        };
    } catch (err) {
        return err
    }
}


export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { teamId, status, body } = await getUserDetails(request);
    if (teamId !== '0') return { status: 401, body: { message: 'You do not have permission to do this. \nAsk an admin to modify your permissions.' } }
    if (status === 401) return { status, body }

    const name = request.body.get('name') || null
    const value = request.body.get('value') || null

    try {
        await db.prisma.setting.update({ where: { name }, data: { value } })
        return {
            status: 200,
        }
    } catch (err) {
        return PrismaErrorHandler(err)
    }
}