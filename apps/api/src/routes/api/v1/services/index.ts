import { FastifyPluginAsync } from 'fastify';
import {
    activatePlausibleUsers,
    activateWordpressFtp,
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

import type { OnlyId } from '../../../../types';
import type { ActivateWordpressFtp, CheckService, DeleteServiceSecret, DeleteServiceStorage, GetServiceLogs, SaveService, SaveServiceDestination, SaveServiceSecret, SaveServiceSettings, SaveServiceStorage, SaveServiceType, SaveServiceVersion, ServiceStartStop, SetWordpressSettings } from './types';

const root: FastifyPluginAsync = async (fastify): Promise<void> => {
    fastify.addHook('onRequest', async (request) => {
        return await request.jwtVerify()
    })
    fastify.get('/', async (request) => await listServices(request));
    fastify.post('/new', async (request, reply) => await newService(request, reply));

    fastify.get<OnlyId>('/:id', async (request) => await getService(request));
    fastify.post<SaveService>('/:id', async (request, reply) => await saveService(request, reply));
    fastify.delete<OnlyId>('/:id', async (request) => await deleteService(request));

    fastify.post<CheckService>('/:id/check', async (request) => await checkService(request));

    fastify.post<SaveServiceSettings>('/:id/settings', async (request, reply) => await saveServiceSettings(request, reply));

    fastify.get<OnlyId>('/:id/secrets', async (request) => await getServiceSecrets(request));
    fastify.post<SaveServiceSecret>('/:id/secrets', async (request, reply) => await saveServiceSecret(request, reply));
    fastify.delete<DeleteServiceSecret>('/:id/secrets', async (request) => await deleteServiceSecret(request));

    fastify.get<OnlyId>('/:id/storages', async (request) => await getServiceStorages(request));
    fastify.post<SaveServiceStorage>('/:id/storages', async (request, reply) => await saveServiceStorage(request, reply));
    fastify.delete<DeleteServiceStorage>('/:id/storages', async (request) => await deleteServiceStorage(request));

    fastify.get('/:id/configuration/type', async (request) => await getServiceType(request));
    fastify.post<SaveServiceType>('/:id/configuration/type', async (request, reply) => await saveServiceType(request, reply));

    fastify.get<OnlyId>('/:id/configuration/version', async (request) => await getServiceVersions(request));
    fastify.post<SaveServiceVersion>('/:id/configuration/version', async (request, reply) => await saveServiceVersion(request, reply));

    fastify.post<SaveServiceDestination>('/:id/configuration/destination', async (request, reply) => await saveServiceDestination(request, reply));

    fastify.get<OnlyId>('/:id/usage', async (request) => await getServiceUsage(request));
    fastify.get<GetServiceLogs>('/:id/logs', async (request) => await getServiceLogs(request));

    fastify.post<ServiceStartStop>('/:id/:type/start', async (request) => await startService(request));
    fastify.post<ServiceStartStop>('/:id/:type/stop', async (request) => await stopService(request));
    fastify.post<ServiceStartStop & SetWordpressSettings>('/:id/:type/settings', async (request, reply) => await setSettingsService(request, reply));

    fastify.post<OnlyId>('/:id/plausibleanalytics/activate', async (request, reply) => await activatePlausibleUsers(request, reply));
    fastify.post<ActivateWordpressFtp>('/:id/wordpress/ftp', async (request, reply) => await activateWordpressFtp(request, reply));
};

export default root;
