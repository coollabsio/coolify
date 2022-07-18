import { FastifyPluginAsync } from 'fastify';
import { checkDNS, checkDomain, deleteDomain, listAllSettings, saveSettings } from './handlers';
import { CheckDNS, CheckDomain, DeleteDomain, SaveSettings } from './types';


const root: FastifyPluginAsync = async (fastify): Promise<void> => {
    fastify.addHook('onRequest', async (request) => {
        return await request.jwtVerify()
    })
    fastify.get('/', async (request) => await listAllSettings(request));
    fastify.post<SaveSettings>('/', async (request, reply) => await saveSettings(request, reply));
    fastify.delete<DeleteDomain>('/', async (request, reply) => await deleteDomain(request, reply));

    fastify.get<CheckDNS>('/check', async (request) => await checkDNS(request));
    fastify.post<CheckDomain>('/check', async (request) => await checkDomain(request));
};

export default root;
