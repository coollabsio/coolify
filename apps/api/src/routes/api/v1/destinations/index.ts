import { FastifyPluginAsync } from 'fastify';
import { checkDestination, deleteDestination, getDestination, listDestinations, newDestination, restartProxy, saveDestinationSettings, startProxy, stopProxy } from './handlers';

const root: FastifyPluginAsync = async (fastify, opts): Promise<void> => {
    fastify.addHook('onRequest', async (request, reply) => {
        return await request.jwtVerify()
    })
    fastify.get('/', async (request) => await listDestinations(request));
    fastify.post('/check', async (request) => await checkDestination(request));

    fastify.get('/:id', async (request) => await getDestination(request));
    fastify.post('/:id', async (request, reply) => await newDestination(request, reply));
    fastify.delete('/:id', async (request) => await deleteDestination(request));

    fastify.post('/:id/settings', async (request, reply) => await saveDestinationSettings(request, reply));
    fastify.post('/:id/start', async (request, reply) => await startProxy(request, reply));
    fastify.post('/:id/stop', async (request, reply) => await stopProxy(request, reply));
    fastify.post('/:id/restart', async (request, reply) => await restartProxy(request, reply));



};

export default root;
