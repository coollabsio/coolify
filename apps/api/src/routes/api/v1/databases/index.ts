import { FastifyPluginAsync } from 'fastify';
import { deleteDatabase, getDatabase, getDatabaseLogs, getDatabaseTypes, getDatabaseUsage, getVersions, listDatabases, newDatabase, saveDatabase, saveDatabaseDestination, saveDatabaseSettings, saveDatabaseType, saveVersion, startDatabase, stopDatabase } from './handlers';

const root: FastifyPluginAsync = async (fastify, opts): Promise<void> => {
    fastify.addHook('onRequest', async (request, reply) => {
        return await request.jwtVerify()
    })
    fastify.get('/', async (request) => await listDatabases(request));
    fastify.post('/new', async (request, reply) => await newDatabase(request, reply));

    fastify.get('/:id', async (request) => await getDatabase(request));
    fastify.post('/:id', async (request, reply) => await saveDatabase(request, reply));
    fastify.delete('/:id', async (request) => await deleteDatabase(request));

    fastify.post('/:id/settings', async (request) => await saveDatabaseSettings(request));

    fastify.get('/:id/configuration/type', async (request) => await getDatabaseTypes(request));
    fastify.post('/:id/configuration/type', async (request, reply) => await saveDatabaseType(request, reply));

    fastify.get('/:id/configuration/version', async (request) => await getVersions(request));
    fastify.post('/:id/configuration/version', async (request, reply) => await saveVersion(request, reply));

    fastify.post('/:id/configuration/destination', async (request, reply) => await saveDatabaseDestination(request, reply));

    fastify.get('/:id/usage', async (request) => await getDatabaseUsage(request));
    fastify.get('/:id/logs', async (request) => await getDatabaseLogs(request));

    fastify.post('/:id/start', async (request) => await startDatabase(request));
    fastify.post('/:id/stop', async (request) => await stopDatabase(request));
};

export default root;
