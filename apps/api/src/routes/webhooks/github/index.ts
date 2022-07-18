import { FastifyPluginAsync } from 'fastify';
import { configureGitHubApp, gitHubEvents, installGithub } from './handlers';

import type { GitHubEvents, InstallGithub } from './types';

const root: FastifyPluginAsync = async (fastify): Promise<void> => {
    fastify.get('/', async (request, reply) => configureGitHubApp(request, reply));
    fastify.get<InstallGithub>('/install', async (request, reply) => installGithub(request, reply));
    fastify.post<GitHubEvents>('/events', async (request, reply) => gitHubEvents(request));
};

export default root;
