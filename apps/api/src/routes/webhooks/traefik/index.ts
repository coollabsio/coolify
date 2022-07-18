import { FastifyPluginAsync } from 'fastify';
import { traefikConfiguration, traefikOtherConfiguration } from './handlers';
import { TraefikOtherConfiguration } from './types';

const root: FastifyPluginAsync = async (fastify): Promise<void> => {
    fastify.get('/main.json', async (request, reply) => traefikConfiguration(request, reply));
    fastify.get<TraefikOtherConfiguration>('/other.json', async (request, reply) => traefikOtherConfiguration(request));
};

export default root;
