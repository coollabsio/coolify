import { FastifyPluginAsync } from 'fastify';
import { checkDNS, checkDomain, deleteDomain, listAllSettings, saveSettings } from './handlers';


const root: FastifyPluginAsync = async (fastify, opts): Promise<void> => {
    fastify.addHook('onRequest', async (request, reply) => {
        return await request.jwtVerify()
    })
    fastify.get('/', async (request) => await listAllSettings(request));
    fastify.post('/', async (request, reply) => await saveSettings(request, reply));
    fastify.delete('/', async (request, reply) => await deleteDomain(request, reply));

    fastify.get('/check', async (request, reply) => await checkDNS(request, reply));
    fastify.post('/check', async (request, reply) => await checkDomain(request, reply));
};

export default root;
