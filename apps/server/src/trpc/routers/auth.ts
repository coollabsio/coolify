import { z } from 'zod';
import { publicProcedure, router } from '../trpc';
import { TRPCError } from '@trpc/server';
import { comparePassword, hashPassword, listSettings, uniqueName } from '../../lib/common';
import { env } from '../../env';
import jsonwebtoken from 'jsonwebtoken';
import { prisma } from '../../prisma';
import cuid from 'cuid';

export const authRouter = router({
	register: publicProcedure
		.input(
			z.object({
				email: z.string(),
				password: z.string()
			})
		)
		.mutation(async ({ input }) => {
			const { email, password } = input;
			const userFound = await prisma.user.findUnique({
				where: { email },
				include: { teams: true, permission: true }
			});

			if (userFound) {
				throw new TRPCError({
					code: 'BAD_REQUEST',
					message: 'User already exists.'
				});
			}
			const settings = await listSettings();
			if (!settings?.isRegistrationEnabled) {
				throw new TRPCError({
					code: 'FORBIDDEN',
					message: 'Registration is disabled.'
				});
			}
			const usersCount = await prisma.user.count();
			const uid = usersCount === 0 ? '0' : cuid();
			const permission = 'owner';
			const isAdmin = true;
			const hashedPassword = await hashPassword(password);

			// Create the first user as the owner
			if (usersCount === 0) {
				await prisma.user.create({
					data: {
						id: uid,
						email,
						password: hashedPassword,
						type: 'email',
						teams: {
							create: {
								id: uid,
								name: uniqueName(),
								destinationDocker: { connect: { network: 'coolify' } }
							}
						},
						permission: { create: { teamId: uid, permission } }
					},
					include: { teams: true }
				});
				await prisma.setting.update({
					where: { id: '0' },
					data: { isRegistrationEnabled: false }
				});
			} else {
				// Create a new user and team
				await prisma.user.create({
					data: {
						id: uid,
						email,
						password: hashedPassword,
						type: 'email',
						teams: {
							create: {
								id: uid,
								name: uniqueName()
							}
						},
						permission: { create: { teamId: uid, permission } }
					},
					include: { teams: true }
				});
			}
			const payload = {
				userId: uid,
				teamId: uid,
				permission,
				isAdmin
			};
			return {
				...payload,
				token: jsonwebtoken.sign(payload, env.COOLIFY_SECRET_KEY)
			};
		}),
	login: publicProcedure
		.input(
			z.object({
				email: z.string(),
				password: z.string()
			})
		)
		.mutation(async ({ input }) => {
			const { email, password } = input;
			const userFound = await prisma.user.findUnique({
				where: { email },
				include: { teams: true, permission: true }
			});

			if (!userFound) {
				throw new TRPCError({
					code: 'BAD_REQUEST',
					message: 'User already exists.'
				});
			}
			if (userFound.type === 'email') {
				if (userFound.password === 'RESETME') {
					const hashedPassword = await hashPassword(password);
					if (userFound.updatedAt < new Date(Date.now() - 1000 * 60 * 10)) {
						if (userFound.id === '0') {
							await prisma.user.update({
								where: { email: userFound.email },
								data: { password: 'RESETME' }
							});
						} else {
							await prisma.user.update({
								where: { email: userFound.email },
								data: { password: 'RESETTIMEOUT' }
							});
						}
					} else {
						await prisma.user.update({
							where: { email: userFound.email },
							data: { password: hashedPassword }
						});
						const payload = {
							userId: userFound.id,
							teamId: userFound.id,
							permission: userFound.permission,
							isAdmin: true
						};
						return {
							...payload,
							token: jsonwebtoken.sign(payload, env.COOLIFY_SECRET_KEY)
						};
					}
				}
				if (!userFound.password) {
					throw new TRPCError({
						code: 'BAD_REQUEST',
						message: 'Something went wrong. Please try again later.'
					});
				}
				const passwordMatch = comparePassword(password, userFound.password);
				if (!passwordMatch) {
					throw new TRPCError({
						code: 'BAD_REQUEST',
						message: 'Incorrect password.'
					});
				}
				const payload = {
					userId: userFound.id,
					teamId: userFound.id,
					permission: userFound.permission,
					isAdmin: true
				};
				return {
					...payload,
					token: jsonwebtoken.sign(payload, env.COOLIFY_SECRET_KEY)
				};
			}
			throw new TRPCError({
				code: 'BAD_REQUEST',
				message: 'Not implemented yet.'
			});
		})
});
