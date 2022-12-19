import { initTRPC, TRPCError } from '@trpc/server';
import superjson from 'superjson';
import type { Context } from './context';

const t = initTRPC.context<Context>().create({
	transformer: superjson,
	errorFormatter({ shape }) {
		return shape;
	}
});
const logger = t.middleware(async ({ path, type, next }) => {
	const start = Date.now();
	const result = await next();
	const durationMs = Date.now() - start;
	result.ok
		? console.log('OK request timing:', { path, type, durationMs })
		: console.log('Non-OK request timing', { path, type, durationMs });
	return result;
});

const isAdmin = t.middleware(async ({ ctx, next }) => {
	if (!ctx.user) {
		throw new TRPCError({ code: 'UNAUTHORIZED' });
	}
	return next({
		ctx: {
			user: ctx.user
		}
	});
});
export const router = t.router;
export const privateProcedure = t.procedure.use(isAdmin);
export const publicProcedure = t.procedure;
