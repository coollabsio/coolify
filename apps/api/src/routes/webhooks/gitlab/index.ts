import { FastifyPluginAsync } from 'fastify';
import { configureGitLabApp, gitLabEvents } from './handlers';

const root: FastifyPluginAsync = async (fastify, opts): Promise<void> => {
    fastify.get('/', async (request, reply) => configureGitLabApp(request, reply));
    fastify.post('/events', async (request, reply) => gitLabEvents(request, reply));
};

export default root;
