import { FastifyPluginAsync } from 'fastify';
import { traefikConfiguration, traefikOtherConfiguration } from './handlers';

const root: FastifyPluginAsync = async (fastify, opts): Promise<void> => {
    fastify.get('/main.json', async (request, reply) => traefikConfiguration(request, reply));
    fastify.get('/other.json', async (request, reply) => traefikOtherConfiguration(request, reply));
};

export default root;
