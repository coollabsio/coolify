import { FastifyPluginAsync } from 'fastify';
import { configureGitLabApp, gitLabEvents } from './handlers';

import type { ConfigureGitLabApp, GitLabEvents } from './types';

const root: FastifyPluginAsync = async (fastify): Promise<void> => {
    fastify.get<ConfigureGitLabApp>('/', async (request, reply) => configureGitLabApp(request, reply));
    fastify.post<GitLabEvents>('/events', async (request) => gitLabEvents(request));
};

export default root;
