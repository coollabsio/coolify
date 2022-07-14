import { FastifyPluginAsync } from 'fastify';
import { checkDestination, deleteDestination, getDestination, listDestinations, newDestination, restartProxy, saveDestinationSettings, startProxy, stopProxy } from './handlers';

import type { OnlyId } from '../../../../types';
import type { CheckDestination, NewDestination, Proxy, SaveDestinationSettings } from './types';

const root: FastifyPluginAsync = async (fastify): Promise<void> => {
    fastify.addHook('onRequest', async (request) => {
        return await request.jwtVerify()
    })
    fastify.get('/', async (request) => await listDestinations(request));
    fastify.post<CheckDestination>('/check', async (request) => await checkDestination(request));

    fastify.get<OnlyId>('/:id', async (request) => await getDestination(request));
    fastify.post<NewDestination>('/:id', async (request, reply) => await newDestination(request, reply));
    fastify.delete<OnlyId>('/:id', async (request) => await deleteDestination(request));

    fastify.post<SaveDestinationSettings>('/:id/settings', async (request, reply) => await saveDestinationSettings(request));
    fastify.post<Proxy>('/:id/start', async (request, reply) => await startProxy(request));
    fastify.post<Proxy>('/:id/stop', async (request, reply) => await stopProxy(request));
    fastify.post<Proxy>('/:id/restart', async (request, reply) => await restartProxy(request));
};

export default root;
