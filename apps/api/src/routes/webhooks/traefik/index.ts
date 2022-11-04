import { FastifyPluginAsync } from 'fastify';
import { OnlyId } from '../../../types';
import { proxyConfiguration, otherProxyConfiguration } from './handlers';
import { OtherProxyConfiguration } from './types';

const root: FastifyPluginAsync = async (fastify): Promise<void> => {
    fastify.get<OnlyId>('/main.json', async (request, reply) => proxyConfiguration(request, false));
    fastify.get<OnlyId>('/remote/:id', async (request) => proxyConfiguration(request, true));
    fastify.get<OtherProxyConfiguration>('/other.json', async (request, reply) => otherProxyConfiguration(request));
};

export default root;
