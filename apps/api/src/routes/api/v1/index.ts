import { FastifyPluginAsync } from 'fastify';
import { checkUpdate, login, showDashboard, update, resetQueue, getCurrentUser, cleanupManually, restartCoolify, backup } from './handlers';
import { GetCurrentUser } from './types';

export interface Update {
	Body: { latestVersion: string }
}
export interface Login {
	Body: { email: string, password: string, isLogin: boolean }
}

const root: FastifyPluginAsync = async (fastify): Promise<void> => {
	fastify.get('/', async function (_request, reply) {
		return reply.redirect(302, '/');
	});
	fastify.post<Login>('/login', async (request, reply) => {
		const payload = await login(request, reply)
		const token = fastify.jwt.sign(payload)
		return { token, payload }
	});

	fastify.get<GetCurrentUser>('/user', {
		onRequest: [fastify.authenticate]
	}, async (request) => await getCurrentUser(request, fastify));

	fastify.get('/undead', async function () {
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

	fastify.post('/internal/restart', {
		onRequest: [fastify.authenticate]
	}, async (request) => await restartCoolify(request));

	fastify.post('/internal/resetQueue', {
		onRequest: [fastify.authenticate]
	}, async (request) => await resetQueue(request));

	fastify.post('/internal/cleanup', {
		onRequest: [fastify.authenticate]
	}, async (request) => await cleanupManually(request));

	// fastify.get('/internal/backup/:backupData', {
	// 	onRequest: [fastify.authenticate]
	// }, async (request) => await backup(request));
};

export default root;
