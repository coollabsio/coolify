import { FastifyPluginAsync } from 'fastify';
import {
    activatePlausibleUsers,
    checkService,
    deleteService,
    deleteServiceSecret,
    deleteServiceStorage,
    getService,
    getServiceLogs,
    getServiceSecrets,
    getServiceStorages,
    getServiceType,
    getServiceUsage,
    getServiceVersions,
    listServices,
    newService,
    saveService,
    saveServiceDestination,
    saveServiceSecret,
    saveServiceSettings,
    saveServiceStorage,
    saveServiceType,
    saveServiceVersion,
    setSettingsService,
    startService,
    stopService
} from './handlers';

const root: FastifyPluginAsync = async (fastify, opts): Promise<void> => {
    fastify.addHook('onRequest', async (request, reply) => {
        return await request.jwtVerify()
    })
    fastify.get('/', async (request) => await listServices(request));
    fastify.post('/new', async (request, reply) => await newService(request, reply));

    fastify.get('/:id', async (request) => await getService(request));
    fastify.post('/:id', async (request, reply) => await saveService(request, reply));
    fastify.delete('/:id', async (request) => await deleteService(request));

    fastify.post('/:id/check', async (request) => await checkService(request));

    fastify.post('/:id/settings', async (request, reply) => await saveServiceSettings(request, reply));

    fastify.get('/:id/secrets', async (request) => await getServiceSecrets(request));
    fastify.post('/:id/secrets', async (request, reply) => await saveServiceSecret(request, reply));
    fastify.delete('/:id/secrets', async (request) => await deleteServiceSecret(request));

    fastify.get('/:id/storages', async (request) => await getServiceStorages(request));
    fastify.post('/:id/storages', async (request, reply) => await saveServiceStorage(request, reply));
    fastify.delete('/:id/storages', async (request) => await deleteServiceStorage(request));

    fastify.get('/:id/configuration/type', async (request) => await getServiceType(request));
    fastify.post('/:id/configuration/type', async (request, reply) => await saveServiceType(request, reply));

    fastify.get('/:id/configuration/version', async (request) => await getServiceVersions(request));
    fastify.post('/:id/configuration/version', async (request, reply) => await saveServiceVersion(request, reply));

    fastify.post('/:id/configuration/destination', async (request, reply) => await saveServiceDestination(request, reply));

    fastify.get('/:id/usage', async (request) => await getServiceUsage(request));
    fastify.get('/:id/logs', async (request) => await getServiceLogs(request));

    fastify.post('/:id/:type/start', async (request) => await startService(request));
    fastify.post('/:id/:type/stop', async (request) => await stopService(request));
    fastify.post('/:id/:type/settings', async (request, reply) => await setSettingsService(request, reply));

    fastify.post('/:id/plausibleanalytics/activate', async (request, reply) => await activatePlausibleUsers(request, reply));
};

export default root;
