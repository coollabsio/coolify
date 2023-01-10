import type { inferAsyncReturnType } from '@trpc/server';
import type { CreateFastifyContextOptions } from '@trpc/server/adapters/fastify';
import jwt from 'jsonwebtoken';
import { env } from '../env';
export interface User {
	userId: string;
	teamId: string;
	permission: string;
	isAdmin: boolean;
	iat: number;
}

export function createContext({ req }: CreateFastifyContextOptions) {
	const token = req.headers.authorization;
	let user: User | null = null;
	if (token) {
		user = jwt.verify(token, env.COOLIFY_SECRET_KEY) as User;
	}
	return { user, hostname: req.hostname };
}

export type Context = inferAsyncReturnType<typeof createContext>;
