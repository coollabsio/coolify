import { inferAsyncReturnType } from '@trpc/server';
import { CreateFastifyContextOptions } from '@trpc/server/adapters/fastify';
import jwt from 'jsonwebtoken';
import { env } from '../env';
export interface User {
	name: string | string[];
}

export function createContext({ req, res }: CreateFastifyContextOptions) {
	const token = req.headers.authorization;
	if (token) {
		const user = jwt.verify(token, env.COOLIFY_SECRET_KEY);

		console.log(user);
	}
	return { req, res };
}

export type Context = inferAsyncReturnType<typeof createContext>;
