import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { PrismaErrorHandler } from '$lib/database';
import { dayjs } from '$lib/dayjs';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }

    const userId = request.body.get('userId')
    const newPermission = request.body.get('newPermission')
    const permissionId = request.body.get('permissionId')
    
    try {
        await db.prisma.permission.updateMany({ where: { id: permissionId, userId }, data: { permission: { set: newPermission } } })
        return {
            status: 200
        }
    } catch (err) {
        return PrismaErrorHandler(err)
    }

}