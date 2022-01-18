import { asyncExecShell, getEngine, getTeam, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { checkContainer } from '$lib/haproxy';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (request) => {
    const { teamId, status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }

    const { id } = request.params
    const destination = await db.getDestination({ id, teamId })
    const state = await checkContainer(destination.engine, 'coolify-haproxy')

    return {
        body: {
            destination,
            state
        }
    };
}
export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { teamId, status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }

    const { id } = request.params
    const name = request.body.get('name') || undefined
    const isSwarm = request.body.get('isSwarm') || undefined
    const engine = request.body.get('engine') || undefined
    const network = request.body.get('network') || undefined
    return {
        body: {
            destination: await db.updateDestination({ id, name, isSwarm, engine, network })
        }
    };
}

export const del: RequestHandler = async (request) => {
    const { teamId, status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }

    const { id } = request.params
    try {
        return {
            body: {
                destination: await db.removeDestination({ id })
            }
        };
    } catch (err) {
        return err
    }

}

