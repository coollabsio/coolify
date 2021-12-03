import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { dayjs } from '$lib/dayjs';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { userId, status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }

    const id = request.body.get('id')
    try {
        await db.prisma.teamInvitation.delete({ where: { id } })
        return {
            status: 200
        }
    } catch (err) {
        return {
            status: 500,
            body: {
                message: "Invitation not found."
            }
        }
    }

}