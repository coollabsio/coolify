// import { z } from 'zod';
import { publicProcedure, privateProcedure, router } from '../trpc';
import { TRPCError } from '@trpc/server';
import { getCurrentUser, getTeamInvitation, listSettings, version } from '../../lib/common';
import { env } from '../../env';
import type { Permission, TeamInvitation } from '@prisma/client';
import jsonwebtoken from 'jsonwebtoken';

export const settingsRouter = router({
	getBaseSettings: publicProcedure.query(async () => {
		const settings = await listSettings();
		return {
			success: true,
			data: {
				isRegistrationEnabled: settings?.isRegistrationEnabled
			}
		};
	}),
	getInstanceSettings: privateProcedure.query(async ({ ctx }) => {
		try {
			const settings = await listSettings();

			let isAdmin = false;
			let permission = null;
			let token = null;
			let pendingInvitations: TeamInvitation[] = [];

			if (!settings) {
				throw new TRPCError({
					code: 'INTERNAL_SERVER_ERROR',
					message: 'An unexpected error occurred, please try again later.'
				});
			}
			if (ctx.user) {
				const currentUser = await getCurrentUser(ctx.user.userId);
				if (currentUser) {
					const foundPermission = currentUser.permission.find(
						(p: Permission) => p.teamId === ctx.user?.teamId
					)?.permission;
					if (foundPermission) {
						permission = foundPermission;
						isAdmin = foundPermission === 'owner' || foundPermission === 'admin';
					}
					const payload = {
						userId: ctx.user?.userId,
						teamId: ctx.user?.teamId,
						permission,
						isAdmin,
						iat: Math.floor(Date.now() / 1000)
					};
					token = jsonwebtoken.sign(payload, env.COOLIFY_SECRET_KEY);
				}
				pendingInvitations = await getTeamInvitation(ctx.user.userId);
			}
			return {
				success: true,
				data: {
					token,
					userId: ctx.user?.userId,
					teamId: ctx.user?.teamId,
					permission,
					isAdmin,
					ipv4: ctx.user?.teamId ? settings.ipv4 : null,
					ipv6: ctx.user?.teamId ? settings.ipv6 : null,
					version,
					whiteLabeled: env.COOLIFY_WHITE_LABELED === 'true',
					whiteLabeledIcon: env.COOLIFY_WHITE_LABELED_ICON,
					isRegistrationEnabled: settings.isRegistrationEnabled,
					pendingInvitations
				}
			};
		} catch (error) {
			throw new TRPCError({
				code: 'INTERNAL_SERVER_ERROR',
				message: 'An unexpected error occurred, please try again later.',
				cause: error
			});
		}
	})
});
