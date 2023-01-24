import { z } from 'zod';
import { privateProcedure, router } from '../../trpc';
import { decrypt, encrypt } from '../../../lib/common';
import { prisma } from '../../../prisma';

import cuid from 'cuid';

export const sourcesRouter = router({
	save: privateProcedure
		.input(
			z.object({
				id: z.string(),
				name: z.string(),
				htmlUrl: z.string(),
				apiUrl: z.string(),
				customPort: z.number(),
				customUser: z.string(),
				isSystemWide: z.boolean().default(false)
			})
		)
		.mutation(async ({ input, ctx }) => {
			let { id, name, htmlUrl, apiUrl, customPort, customUser, isSystemWide } = input;
			if (customPort) customPort = Number(customPort);
			await prisma.gitSource.update({
				where: { id },
				data: { name, htmlUrl, apiUrl, customPort, customUser, isSystemWide }
			});
		}),
	newGitHubApp: privateProcedure
		.input(
			z.object({
				id: z.string(),
				name: z.string(),
				htmlUrl: z.string(),
				apiUrl: z.string(),
				organization: z.string(),
				customPort: z.number(),
				isSystemWide: z.boolean().default(false)
			})
		)
		.mutation(async ({ ctx, input }) => {
			const { teamId } = ctx.user;
			let { id, name, htmlUrl, apiUrl, organization, customPort, isSystemWide } = input;

			if (customPort) customPort = Number(customPort);
			if (id === 'new') {
				const newId = cuid();
				await prisma.gitSource.create({
					data: {
						id: newId,
						name,
						htmlUrl,
						apiUrl,
						organization,
						customPort,
						isSystemWide,
						type: 'github',
						teams: { connect: { id: teamId } }
					}
				});
				return {
					id: newId
				};
			}
			return null;
		}),
	newGitLabApp: privateProcedure
		.input(
			z.object({
				id: z.string(),
				type: z.string(),
				name: z.string(),
				htmlUrl: z.string(),
				apiUrl: z.string(),
				oauthId: z.number(),
				appId: z.string(),
				appSecret: z.string(),
				groupName: z.string().optional().nullable(),
				customPort: z.number().optional().nullable(),
				customUser: z.string().optional().nullable()
			})
		)
		.mutation(async ({ input, ctx }) => {
			const { teamId } = ctx.user;
			let {
				id,
				type,
				name,
				htmlUrl,
				apiUrl,
				oauthId,
				appId,
				appSecret,
				groupName,
				customPort,
				customUser
			} = input;

			if (oauthId) oauthId = Number(oauthId);
			if (customPort) customPort = Number(customPort);
			const encryptedAppSecret = encrypt(appSecret);

			if (id === 'new') {
				const newId = cuid();
				await prisma.gitSource.create({
					data: {
						id: newId,
						type,
						apiUrl,
						htmlUrl,
						name,
						customPort,
						customUser,
						teams: { connect: { id: teamId } }
					}
				});
				await prisma.gitlabApp.create({
					data: {
						teams: { connect: { id: teamId } },
						appId,
						oauthId,
						groupName,
						appSecret: encryptedAppSecret,
						gitSource: { connect: { id: newId } }
					}
				});
				return {
					status: 201,
					id: newId
				};
			} else {
				await prisma.gitSource.update({
					where: { id },
					data: { type, apiUrl, htmlUrl, name, customPort, customUser }
				});
				await prisma.gitlabApp.update({
					where: { id },
					data: {
						appId,
						oauthId,
						groupName,
						appSecret: encryptedAppSecret
					}
				});
			}
		}),
	delete: privateProcedure
		.input(
			z.object({
				id: z.string()
			})
		)
		.mutation(async ({ input, ctx }) => {
			const { id } = input;
			const source = await prisma.gitSource.delete({
				where: { id },
				include: { githubApp: true, gitlabApp: true }
			});
			if (source.githubAppId) {
				await prisma.githubApp.delete({ where: { id: source.githubAppId } });
			}
			if (source.gitlabAppId) {
				await prisma.gitlabApp.delete({ where: { id: source.gitlabAppId } });
			}
		}),
	getSourceById: privateProcedure
		.input(
			z.object({
				id: z.string()
			})
		)
		.query(async ({ input, ctx }) => {
			const { id } = input;
			const { teamId } = ctx.user;
			const settings = await prisma.setting.findFirst({});

			if (id === 'new') {
				return {
					source: {
						name: null,
						type: null,
						htmlUrl: null,
						apiUrl: null,
						organization: null,
						customPort: 22,
						customUser: 'git'
					},
					settings
				};
			}

			const source = await prisma.gitSource.findFirst({
				where: {
					id,
					OR: [
						{ teams: { some: { id: teamId === '0' ? undefined : teamId } } },
						{ isSystemWide: true }
					]
				},
				include: { githubApp: true, gitlabApp: true }
			});
			if (!source) {
				throw { status: 404, message: 'Source not found.' };
			}

			if (source?.githubApp?.clientSecret)
				source.githubApp.clientSecret = decrypt(source.githubApp.clientSecret);
			if (source?.githubApp?.webhookSecret)
				source.githubApp.webhookSecret = decrypt(source.githubApp.webhookSecret);
			if (source?.githubApp?.privateKey)
				source.githubApp.privateKey = decrypt(source.githubApp.privateKey);
			if (source?.gitlabApp?.appSecret)
				source.gitlabApp.appSecret = decrypt(source.gitlabApp.appSecret);

			return {
				success: true,
				data: {
					source,
					settings
				}
			};
		})
});
