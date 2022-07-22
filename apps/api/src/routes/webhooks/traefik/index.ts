import { FastifyPluginAsync } from 'fastify';
import { remoteTraefikConfiguration, traefikConfiguration, traefikOtherConfiguration } from './handlers';
import { TraefikOtherConfiguration } from './types';

const root: FastifyPluginAsync = async (fastify): Promise<void> => {
    fastify.get('/main.json', async (request, reply) => traefikConfiguration(request, reply));
    fastify.get<TraefikOtherConfiguration>('/other.json', async (request, reply) => traefikOtherConfiguration(request));

    fastify.get('/remote/:id', async (request) => remoteTraefikConfiguration(request));
};

export default root;
