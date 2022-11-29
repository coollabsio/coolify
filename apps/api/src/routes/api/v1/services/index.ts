import { FastifyPluginAsync } from 'fastify';
import {
    activatePlausibleUsers,
    activateWordpressFtp,
    checkService,
    checkServiceDomain,
    cleanupPlausibleLogs,
    cleanupUnconfiguredServices,
    deleteService,
    deleteServiceSecret,
    deleteServiceStorage,
    getService,
    getServiceLogs,
    getServiceSecrets,
    getServiceStatus,
    getServiceStorages,
    getServiceType,
    getServiceUsage,
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
} from './handlers';

import type { OnlyId } from '../../../../types';
import type { ActivateWordpressFtp, CheckService, CheckServiceDomain, DeleteServiceSecret, DeleteServiceStorage, GetServiceLogs, SaveService, SaveServiceDestination, SaveServiceSecret, SaveServiceSettings, SaveServiceStorage, SaveServiceType, SaveServiceVersion, ServiceStartStop, SetGlitchTipSettings, SetWordpressSettings } from './types';
import { migrateAppwriteDB, startService, stopService } from '../../../../lib/services/handlers';

const root: FastifyPluginAsync = async (fastify): Promise<void> => {
    fastify.addHook('onRequest', async (request) => {
        return await request.jwtVerify()
    })
    fastify.get('/', async (request) => await listServices(request));
    fastify.post('/new', async (request, reply) => await newService(request, reply));

    fastify.post<any>('/cleanup/unconfigured', async (request) => await cleanupUnconfiguredServices(request));

    fastify.get<OnlyId>('/:id', async (request) => await getService(request));
    fastify.post<SaveService>('/:id', async (request, reply) => await saveService(request, reply));
    fastify.delete<OnlyId>('/:id', async (request) => await deleteService(request));

    fastify.get<OnlyId>('/:id/status', async (request) => await getServiceStatus(request));

    fastify.get<CheckServiceDomain>('/:id/check', async (request) => await checkServiceDomain(request));
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

    fastify.post<SaveServiceVersion>('/:id/configuration/version', async (request, reply) => await saveServiceVersion(request, reply));

    fastify.post<SaveServiceDestination>('/:id/configuration/destination', async (request, reply) => await saveServiceDestination(request, reply));

    fastify.get<OnlyId>('/:id/usage', async (request) => await getServiceUsage(request));
    fastify.get<GetServiceLogs>('/:id/logs/:containerId', async (request) => await getServiceLogs(request));

    fastify.post<ServiceStartStop>('/:id/start', async (request) => await startService(request, fastify));
    fastify.post<ServiceStartStop>('/:id/stop', async (request) => await stopService(request));
    fastify.post<ServiceStartStop & SetWordpressSettings & SetGlitchTipSettings>('/:id/:type/settings', async (request, reply) => await setSettingsService(request, reply));

    fastify.post<OnlyId>('/:id/plausibleanalytics/activate', async (request, reply) => await activatePlausibleUsers(request, reply));
    fastify.post<OnlyId>('/:id/plausibleanalytics/cleanup', async (request, reply) => await cleanupPlausibleLogs(request, reply));
    fastify.post<ActivateWordpressFtp>('/:id/wordpress/ftp', async (request, reply) => await activateWordpressFtp(request, reply));

    fastify.post<OnlyId>('/:id/appwrite/migrate', async (request, reply) => await migrateAppwriteDB(request, reply));
};

export default root;
