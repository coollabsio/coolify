import { FastifyPluginAsync } from 'fastify';
import { checkDNS, checkDomain, deleteDomain, deleteSSHKey, listAllSettings, saveSettings, saveSSHKey } from './handlers';
import { CheckDNS, CheckDomain, DeleteDomain, DeleteSSHKey, SaveSettings, SaveSSHKey } from './types';


const root: FastifyPluginAsync = async (fastify): Promise<void> => {
    fastify.addHook('onRequest', async (request) => {
        return await request.jwtVerify()
    })
    fastify.get('/', async (request) => await listAllSettings(request));
    fastify.post<SaveSettings>('/', async (request, reply) => await saveSettings(request, reply));
    fastify.delete<DeleteDomain>('/', async (request, reply) => await deleteDomain(request, reply));

    fastify.get<CheckDNS>('/check', async (request) => await checkDNS(request));
    fastify.post<CheckDomain>('/check', async (request) => await checkDomain(request));

    fastify.post<SaveSSHKey>('/sshKey', async (request, reply) => await saveSSHKey(request, reply));
    fastify.delete<DeleteSSHKey>('/sshKey', async (request, reply) => await deleteSSHKey(request, reply));
};

export default root;
