import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';
export const get: RequestHandler = async (request) => {
    const { id } = request.params
    return {
        body: {
            destination: await db.getDestination({ id })
        }
    };
}
export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { id } = request.params
    const name = request.body.get('name')
    const isSwarm = request.body.get('isSwarm')
    const engine = request.body.get('engine')
    const network = request.body.get('network')
    return {
        body: {
            destination: await db.updateDestination({ id, name, isSwarm, engine, network })
        }
    };
}

export const del: RequestHandler = async (request) => {
    const { id } = JSON.parse(request.body.toString())
    return {
        body: {
            destination: await db.removeDestination({ id })
        }
    };
}

