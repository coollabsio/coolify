import { FastifyPluginAsync } from 'fastify';
import { checkGitLabOAuthID, deleteSource, getSource, listSources, saveGitHubSource, saveGitLabSource, saveSource } from './handlers';

import type { OnlyId } from '../../../../types';
import type { CheckGitLabOAuthId, SaveGitHubSource, SaveGitLabSource } from './types';

const root: FastifyPluginAsync = async (fastify): Promise<void> => {
    fastify.addHook('onRequest', async (request) => {
        return await request.jwtVerify()
    })
    fastify.get('/', async (request) => await listSources(request));

    fastify.get<OnlyId>('/:id', async (request) => await getSource(request));
    fastify.post('/:id', async (request, reply) => await saveSource(request, reply));
    fastify.delete('/:id', async (request) => await deleteSource(request));

    fastify.post<CheckGitLabOAuthId>('/:id/check', async (request) => await checkGitLabOAuthID(request));
    fastify.post<SaveGitHubSource>('/:id/github', async (request) => await saveGitHubSource(request));
    fastify.post<SaveGitLabSource>('/:id/gitlab', async (request) => await saveGitLabSource(request));
};

export default root;
