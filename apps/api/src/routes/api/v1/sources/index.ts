import { FastifyPluginAsync } from 'fastify';
import { checkGitLabOAuthID, deleteSource, getSource, listSources, saveGitHubSource, saveGitLabSource, saveSource } from './handlers';


const root: FastifyPluginAsync = async (fastify, opts): Promise<void> => {
    fastify.addHook('onRequest', async (request, reply) => {
        return await request.jwtVerify()
    })
    fastify.get('/', async (request) => await listSources(request));

    fastify.get('/:id', async (request) => await getSource(request));
    fastify.post('/:id', async (request, reply) => await saveSource(request, reply));
    fastify.delete('/:id', async (request) => await deleteSource(request));

    fastify.post('/:id/check', async (request) => await checkGitLabOAuthID(request));
    fastify.post('/:id/github', async (request, reply) => await saveGitHubSource(request, reply));
    fastify.post('/:id/gitlab', async (request, reply) => await saveGitLabSource(request, reply));

};

export default root;
