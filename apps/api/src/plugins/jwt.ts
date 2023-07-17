import fp from 'fastify-plugin';
import fastifyJwt, { FastifyJWTOptions } from '@fastify/jwt';

declare module '@fastify/jwt' {
	interface FastifyJWT {
		user: {
			userId: string;
			teamId: string;
			permission: string;
			isAdmin: boolean;
		};
	}
}

export default fp<FastifyJWTOptions>(async (fastify, opts) => {
	let secretKey = fastify.config.COOLIFY_SECRET_KEY_BETTER;
	if (!secretKey) {
		secretKey = fastify.config.COOLIFY_SECRET_KEY;
	}
	fastify.register(fastifyJwt, {
		secret: secretKey
	});

	fastify.decorate('authenticate', async function (request, reply) {
		try {
			await request.jwtVerify();
		} catch (err) {
			reply.send(err);
		}
	});
});

declare module 'fastify' {
	export interface FastifyInstance {
		authenticate(): Promise<void>;
	}
}
