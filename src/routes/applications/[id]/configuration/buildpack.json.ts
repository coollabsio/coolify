import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler<Locals, FormData> = async (request) => {
    const buildPacks = [{ name: 'node' }, { name: 'static' }, { name: 'docker' }];
    return {
        status: 200,
        body: {
            buildPacks
        }
    }
}

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { id } = request.params
    const buildPack = request.body.get('buildPack') || null
    try {
        return await db.configureBuildPack({ id, buildPack })
    } catch (err) {
        return err
    }
}

