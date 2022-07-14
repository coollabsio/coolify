import { FastifyPluginAsync } from 'fastify';
import { deleteDatabase, getDatabase, getDatabaseLogs, getDatabaseTypes, getDatabaseUsage, getVersions, listDatabases, newDatabase, saveDatabase, saveDatabaseDestination, saveDatabaseSettings, saveDatabaseType, saveVersion, startDatabase, stopDatabase } from './handlers';

import type { GetDatabaseLogs, OnlyId, SaveDatabase, SaveDatabaseDestination, SaveDatabaseSettings, SaveVersion } from '../../../../types';
import type { SaveDatabaseType } from './types';

const root: FastifyPluginAsync = async (fastify): Promise<void> => {
    fastify.addHook('onRequest', async (request) => {
        return await request.jwtVerify()
    })
    fastify.get('/', async (request) => await listDatabases(request));
    fastify.post('/new', async (request, reply) => await newDatabase(request, reply));

    fastify.get<OnlyId>('/:id', async (request) => await getDatabase(request));
    fastify.post<SaveDatabase>('/:id', async (request, reply) => await saveDatabase(request, reply));
    fastify.delete<OnlyId>('/:id', async (request) => await deleteDatabase(request));

    fastify.post<SaveDatabaseSettings>('/:id/settings', async (request) => await saveDatabaseSettings(request));

    fastify.get('/:id/configuration/type', async (request) => await getDatabaseTypes(request));
    fastify.post<SaveDatabaseType>('/:id/configuration/type', async (request, reply) => await saveDatabaseType(request, reply));

    fastify.get<OnlyId>('/:id/configuration/version', async (request) => await getVersions(request));
    fastify.post<SaveVersion>('/:id/configuration/version', async (request, reply) => await saveVersion(request, reply));

    fastify.post<SaveDatabaseDestination>('/:id/configuration/destination', async (request, reply) => await saveDatabaseDestination(request, reply));

    fastify.get<OnlyId>('/:id/usage', async (request) => await getDatabaseUsage(request));
    fastify.get<GetDatabaseLogs>('/:id/logs', async (request) => await getDatabaseLogs(request));

    fastify.post<OnlyId>('/:id/start', async (request) => await startDatabase(request));
    fastify.post<OnlyId>('/:id/stop', async (request) => await stopDatabase(request));
};

export default root;
