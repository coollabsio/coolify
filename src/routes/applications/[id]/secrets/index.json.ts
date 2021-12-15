import { getTeam, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (request) => {
    const { teamId, status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }

    const secrets = await db.listSecrets({ applicationId: request.params.id });
    return { status: 200, body: { secrets } };

}

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { teamId, status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }

    const { id } = request.params;
    const name = request.body.get('name');
    const value = request.body.get('value');
    const isBuildSecret = request.body.get('isBuildSecret') === 'true';
    try {
        await db.isSecretExists({ id, name })
        return {
            status: 500,
            body: {
                message: "Secret already exists"
            }
        }
    } catch (err) {
        await db.createSecret({ id, name, value, isBuildSecret });
        return {
            status: 201
        }

    }
}
export const del: RequestHandler = async (request) => {
    const { teamId, status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }
    
    const { id } = request.params;
    const name = request.body.get('name');

    try {
        await db.removeSecret({ id, name });
        return {
            status: 200
        }
    } catch (err) {
        return {
            status: 500
        };
    }

}