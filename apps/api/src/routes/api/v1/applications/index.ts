import { FastifyPluginAsync } from 'fastify';
import { OnlyId } from '../../../../types';
import { cancelDeployment, checkDNS, checkRepository, deleteApplication, deleteSecret, deleteStorage, deployApplication, getApplication, getApplicationLogs, getApplicationStatus, getBuildIdLogs, getBuildLogs, getBuildPack, getGitHubToken, getGitLabSSHKey, getImages, getPreviews, getSecrets, getStorages, getUsage, listApplications, newApplication, saveApplication, saveApplicationSettings, saveApplicationSource, saveBuildPack, saveDeployKey, saveDestination, saveGitLabSSHKey, saveRepository, saveSecret, saveStorage, stopApplication } from './handlers';

import type { CancelDeployment, CheckDNS, CheckRepository, DeleteApplication, DeleteSecret, DeleteStorage, DeployApplication, GetApplicationLogs, GetBuildIdLogs, GetBuildLogs, GetImages, SaveApplication, SaveApplicationSettings, SaveApplicationSource, SaveDeployKey, SaveDestination, SaveSecret, SaveStorage } from './types';

const root: FastifyPluginAsync = async (fastify): Promise<void> => {
    fastify.addHook('onRequest', async (request) => {
        return await request.jwtVerify()
    })
    fastify.get('/', async (request) => await listApplications(request));
    fastify.post<GetImages>('/images', async (request) => await getImages(request));

    fastify.post('/new', async (request, reply) => await newApplication(request, reply));

    fastify.get<OnlyId>('/:id', async (request) => await getApplication(request));
    fastify.post<SaveApplication>('/:id', async (request, reply) => await saveApplication(request, reply));
    fastify.delete<DeleteApplication>('/:id', async (request, reply) => await deleteApplication(request, reply));

    fastify.get<OnlyId>('/:id/status', async (request) => await getApplicationStatus(request));

    fastify.post<OnlyId>('/:id/stop', async (request, reply) => await stopApplication(request, reply));

    fastify.post<SaveApplicationSettings>('/:id/settings', async (request, reply) => await saveApplicationSettings(request, reply));
    fastify.post<CheckDNS>('/:id/check', async (request) => await checkDNS(request));

    fastify.get<OnlyId>('/:id/secrets', async (request) => await getSecrets(request));
    fastify.post<SaveSecret>('/:id/secrets', async (request, reply) => await saveSecret(request, reply));
    fastify.delete<DeleteSecret>('/:id/secrets', async (request) => await deleteSecret(request));

    fastify.get<OnlyId>('/:id/storages', async (request) => await getStorages(request));
    fastify.post<SaveStorage>('/:id/storages', async (request, reply) => await saveStorage(request, reply));
    fastify.delete<DeleteStorage>('/:id/storages', async (request) => await deleteStorage(request));

    fastify.get<OnlyId>('/:id/previews', async (request) => await getPreviews(request));

    fastify.get<GetApplicationLogs>('/:id/logs', async (request) => await getApplicationLogs(request));
    fastify.get<GetBuildLogs>('/:id/logs/build', async (request) => await getBuildLogs(request));
    fastify.get<GetBuildIdLogs>('/:id/logs/build/:buildId', async (request) => await getBuildIdLogs(request));

    fastify.get('/:id/usage', async (request) => await getUsage(request))

    fastify.post<DeployApplication>('/:id/deploy', async (request) => await deployApplication(request))
    fastify.post<CancelDeployment>('/:id/cancel', async (request, reply) => await cancelDeployment(request, reply));

    fastify.post<SaveApplicationSource>('/:id/configuration/source', async (request, reply) => await saveApplicationSource(request, reply));

    fastify.get<CheckRepository>('/:id/configuration/repository', async (request) => await checkRepository(request));
    fastify.post('/:id/configuration/repository', async (request, reply) => await saveRepository(request, reply));
    fastify.post<SaveDestination>('/:id/configuration/destination', async (request, reply) => await saveDestination(request, reply));
    fastify.get('/:id/configuration/buildpack', async (request) => await getBuildPack(request));
    fastify.post('/:id/configuration/buildpack', async (request, reply) => await saveBuildPack(request, reply));

    fastify.get<OnlyId>('/:id/configuration/sshkey', async (request) => await getGitLabSSHKey(request));
    fastify.post<OnlyId>('/:id/configuration/sshkey', async (request, reply) => await saveGitLabSSHKey(request, reply));

    fastify.post<SaveDeployKey>('/:id/configuration/deploykey', async (request, reply) => await saveDeployKey(request, reply));

    fastify.get<OnlyId>('/:id/configuration/githubToken', async (request, reply) => await getGitHubToken(request, reply));
};

export default root;
