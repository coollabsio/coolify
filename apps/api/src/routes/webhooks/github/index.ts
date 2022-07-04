import { FastifyPluginAsync } from 'fastify';
import { configureGitHubApp, gitHubEvents, installGithub } from './handlers';

const root: FastifyPluginAsync = async (fastify, opts): Promise<void> => {
    fastify.get('/', async (request, reply) => configureGitHubApp(request, reply));
    fastify.get('/install', async (request, reply) => installGithub(request, reply));
    fastify.post('/events', async (request, reply) => gitHubEvents(request, reply));
};

export default root;
