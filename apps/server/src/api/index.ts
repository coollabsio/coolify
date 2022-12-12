import type { FastifyPluginAsync } from 'fastify';

const root: FastifyPluginAsync = async (fastify): Promise<void> => {
	fastify.get('/', async function (_request, _reply) {
		return { status: 'ok' };
	});
};
export default root;
