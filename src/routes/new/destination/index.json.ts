import { asyncExecShell, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { dockerInstance } from '$lib/docker';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { teamId, status, body } = await getUserDetails(request)
    if (status === 401) return { status, body }

    const name = request.body.get('name') || null
    const isSwarm = request.body.get('isSwarm') || false
    const engine = request.body.get('engine') || null
    const network = request.body.get('network') || null

    try {
        await db.newDestination({ name, teamId, isSwarm, engine, network })
        const destinationDocker = {
            engine,
            network
        }
        const docker = dockerInstance({ destinationDocker })
        docker.engine.createNetwork({ name: network, attachable: true })
        return { status: 200, body: { message: 'Destination created' } }
    } catch (err) {
        return err
    }
}

