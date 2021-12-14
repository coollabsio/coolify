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
    const isCoolifyProxyUsed = request.body.get('isCoolifyProxyUsed') === 'true' ? true : false

    try {
        const { body } = await db.newDestination({ name, teamId, isSwarm, engine, network, isCoolifyProxyUsed })
        const destinationDocker = {
            engine,
            network
        }
        // TODO Create docker context?
        const docker = dockerInstance({ destinationDocker })
        // if (engine === '/var/run/docker.sock') {
        //     await asyncExecShell(`docker context create ${body.id} --description "${name}" --docker "host=unix:///var/run/docker.sock"`)

        // } else {
        //     await asyncExecShell(`docker context create ${body.id} --description "${name}" --docker "host=${engine}"`)
        // }
        const networks = await docker.engine.listNetworks()
        const found = networks.find(network => network.Name === destinationDocker.network)
        if (!found) {
            await docker.engine.createNetwork({ name: network, attachable: true })
        }
        return { status: 200, body: { message: 'Destination created', id: body.id } }
    } catch (err) {
        return err
    }
}

