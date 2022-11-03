import { FastifyPluginAsync } from 'fastify';
import { OnlyId } from '../../../types';
import { traefikConfiguration, traefikOtherConfiguration } from './handlers';
import { TraefikOtherConfiguration } from './types';

const root: FastifyPluginAsync = async (fastify): Promise<void> => {
    fastify.get<OnlyId>('/main.json', async (request, reply) => traefikConfiguration(request, false));
    fastify.get<OnlyId>('/remote/:id', async (request) => traefikConfiguration(request, true));

    fastify.get<TraefikOtherConfiguration>('/other.json', async (request, reply) => traefikOtherConfiguration(request));
};

export default root;
