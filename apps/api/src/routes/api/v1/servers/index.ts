import { FastifyPluginAsync } from 'fastify';
import { listServers, showUsage } from './handlers';


const root: FastifyPluginAsync = async (fastify): Promise<void> => {
    fastify.addHook('onRequest', async (request) => {
        return await request.jwtVerify()
    })
    fastify.get('/', async (request) => await listServers(request));
    fastify.get('/usage/:id', async (request) => await showUsage(request));

};

export default root;
