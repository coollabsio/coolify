import { FastifyPluginAsync } from 'fastify';
import { cancelDeployment, checkDNS, checkRepository, deleteApplication, deleteSecret, deleteStorage, deployApplication, getApplication, getApplicationLogs, getBuildIdLogs, getBuildLogs, getBuildPack, getGitHubToken, getGitLabSSHKey, getImages, getPreviews, getSecrets, getStorages, getUsage, listApplications, newApplication, saveApplication, saveApplicationSettings, saveApplicationSource, saveBuildPack, saveDeployKey, saveDestination, saveGitLabSSHKey, saveRepository, saveSecret, saveStorage, stopApplication } from './handlers';

export interface GetApplication {
    Params: { id: string; }
}

export interface SaveApplication {
    Params: { id: string; },
    Body: any
}

export interface SaveApplicationSettings {
    Params: { id: string; };
    Querystring: { domain: string; };
    Body: { debug: boolean; previews: boolean; dualCerts: boolean; autodeploy: boolean; branch: string; projectId: number; };
}

export interface DeleteApplication {
    Params: { id: string; };
    Querystring: { domain: string; };
}

export interface CheckDNS {
    Params: { id: string; };
    Querystring: { domain: string; };
}

export interface DeployApplication {
    Params: { id: string },
    Querystring: { domain: string }
    Body: { pullmergeRequestId: string | null, branch: string }
}

const root: FastifyPluginAsync = async (fastify, opts): Promise<void> => {
    fastify.addHook('onRequest', async (request, reply) => {
        return await request.jwtVerify()
    })
    fastify.get('/', async (request) => await listApplications(request));
    fastify.post('/images', async (request) => await getImages(request));

    fastify.post('/new', async (request, reply) => await newApplication(request, reply));

    fastify.get<GetApplication>('/:id', async (request) => await getApplication(request));
    fastify.post<SaveApplication>('/:id', async (request, reply) => await saveApplication(request, reply));
    fastify.delete<DeleteApplication>('/:id', async (request, reply) => await deleteApplication(request, reply));

    fastify.post('/:id/stop', async (request, reply) => await stopApplication(request, reply));

    fastify.post<SaveApplicationSettings>('/:id/settings', async (request, reply) => await saveApplicationSettings(request, reply));
    fastify.post<SaveApplicationSettings>('/:id/check', async (request) => await checkDNS(request));

    fastify.get('/:id/secrets', async (request) => await getSecrets(request));
    fastify.post('/:id/secrets', async (request, reply) => await saveSecret(request, reply));
    fastify.delete('/:id/secrets', async (request) => await deleteSecret(request));

    fastify.get('/:id/storages', async (request) => await getStorages(request));
    fastify.post('/:id/storages', async (request, reply) => await saveStorage(request, reply));
    fastify.delete('/:id/storages', async (request) => await deleteStorage(request));

    fastify.get('/:id/previews', async (request) => await getPreviews(request));

    fastify.get('/:id/logs', async (request) => await getApplicationLogs(request));
    fastify.get('/:id/logs/build', async (request) => await getBuildLogs(request));
    fastify.get('/:id/logs/build/:buildId', async (request) => await getBuildIdLogs(request));

    fastify.get<DeployApplication>('/:id/usage', async (request) => await getUsage(request))

    fastify.post<DeployApplication>('/:id/deploy', async (request) => await deployApplication(request))
    fastify.post('/:id/cancel', async (request, reply) => await cancelDeployment(request, reply));

    fastify.post('/:id/configuration/source', async (request, reply) => await saveApplicationSource(request, reply));

    fastify.get('/:id/configuration/repository', async (request) => await checkRepository(request));
    fastify.post('/:id/configuration/repository', async (request, reply) => await saveRepository(request, reply));
    fastify.post('/:id/configuration/destination', async (request, reply) => await saveDestination(request, reply));
    fastify.get('/:id/configuration/buildpack', async (request) => await getBuildPack(request));
    fastify.post('/:id/configuration/buildpack', async (request, reply) => await saveBuildPack(request, reply));

    fastify.get('/:id/configuration/sshkey', async (request) => await getGitLabSSHKey(request));
    fastify.post('/:id/configuration/sshkey', async (request, reply) => await saveGitLabSSHKey(request, reply));

    fastify.post('/:id/configuration/deploykey', async (request, reply) => await saveDeployKey(request, reply));



    fastify.get('/:id/configuration/githubToken', async (request, reply) => await getGitHubToken(request, reply));
};

export default root;
