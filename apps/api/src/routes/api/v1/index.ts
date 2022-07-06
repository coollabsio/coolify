import { FastifyPluginAsync } from 'fastify';
import { scheduler } from '../../../lib/scheduler';
import { checkUpdate, login, showDashboard, update, showUsage, getCurrentUser } from './handlers';

export interface Update {
	Body: { latestVersion: string }
}
export interface Login {
	Body: { email: string, password: string, isLogin: boolean }
}

const root: FastifyPluginAsync = async (fastify, opts): Promise<void> => {
	fastify.get('/', async function (_request, reply) {
		return reply.redirect(302, '/');
	});
	fastify.post<Login>('/login', async (request, reply) => {
		const payload = await login(request, reply)
		const token = fastify.jwt.sign(payload)
		return { token, payload }
	});

	fastify.get('/user', {
		onRequest: [fastify.authenticate]
	}, async (request) => await getCurrentUser(request, fastify));

	fastify.get('/undead', {
		onRequest: [fastify.authenticate]
	}, async function () {
		return { message: 'nope' };
	});

	fastify.get('/update', {
		onRequest: [fastify.authenticate]
	}, async (request) => await checkUpdate(request));

	fastify.post<Update>(
		'/update', {
		onRequest: [fastify.authenticate]
	},
		async (request) => await update(request)
	);
	fastify.get('/resources', {
		onRequest: [fastify.authenticate]
	}, async (request) => await showDashboard(request));

	fastify.get('/usage', {
		onRequest: [fastify.authenticate]
	}, async () => await showUsage());
};

export default root;
