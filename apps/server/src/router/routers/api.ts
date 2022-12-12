// import { z } from 'zod';
import { publicProcedure, router } from '../trpc';
// import { prisma } from '../../prisma';
import { TRPCError } from '@trpc/server';

export const apiRouter = router({
	getConnection: publicProcedure.query(async () => {
		try {
			return { success: true };
		} catch (error) {
			throw new TRPCError({
				code: 'INTERNAL_SERVER_ERROR',
				message: 'An unexpected error occurred, please try again later.',
				cause: error
			});
		}
	})
});
