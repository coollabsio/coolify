import * as db from '$lib/database';
import { PrismaErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler<Locals> = async (event) => {
    const { id } = event.params
    let { deployKeyId } = await event.request.json()

    deployKeyId = Number(deployKeyId)

    try {
        await db.updateDeployKey({ id, deployKeyId })
        return { status: 201 }
    } catch (error) {
        return PrismaErrorHandler(error)
    }
}


