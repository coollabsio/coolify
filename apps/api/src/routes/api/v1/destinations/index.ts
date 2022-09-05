import { FastifyPluginAsync } from 'fastify';
import { assignSSHKey, checkDestination, deleteDestination, getDestination, getDestinationStatus, listDestinations, newDestination, restartProxy, saveDestinationSettings, startProxy, stopProxy, verifyRemoteDockerEngine } from './handlers';

import type { OnlyId } from '../../../../types';
import type { CheckDestination, ListDestinations, NewDestination, Proxy, SaveDestinationSettings } from './types';

const root: FastifyPluginAsync = async (fastify): Promise<void> => {
    fastify.addHook('onRequest', async (request) => {
        return await request.jwtVerify()
    })
    fastify.get<ListDestinations>('/', async (request) => await listDestinations(request));
    fastify.post<CheckDestination>('/check', async (request) => await checkDestination(request));

    fastify.get<OnlyId>('/:id', async (request) => await getDestination(request));
    fastify.post<NewDestination>('/:id', async (request, reply) => await newDestination(request, reply));
    fastify.delete<OnlyId>('/:id', async (request) => await deleteDestination(request));
    fastify.get<OnlyId>('/:id/status', async (request) => await getDestinationStatus(request));

    fastify.post<SaveDestinationSettings>('/:id/settings', async (request) => await saveDestinationSettings(request));
    fastify.post<Proxy>('/:id/start', async (request,) => await startProxy(request));
    fastify.post<Proxy>('/:id/stop', async (request) => await stopProxy(request));
    fastify.post<Proxy>('/:id/restart', async (request) => await restartProxy(request));

    fastify.post('/:id/configuration/sshKey', async (request) => await assignSSHKey(request));

    fastify.post<OnlyId>('/:id/verify', async (request, reply) => await verifyRemoteDockerEngine(request, reply));
};

export default root;
