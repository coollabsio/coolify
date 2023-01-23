import { z } from 'zod';
import { privateProcedure, router } from '../../trpc';
import {
	listSettings,
	startTraefikProxy,
	startTraefikTCPProxy,
	stopTraefikProxy
} from '../../../lib/common';
import { prisma } from '../../../prisma';

import { executeCommand } from '../../../lib/executeCommand';
import { checkContainer } from '../../../lib/docker';

export const destinationsRouter = router({
	restartProxy: privateProcedure
		.input(
			z.object({
				id: z.string()
			})
		)
		.mutation(async ({ input, ctx }) => {
			const { id } = input;
			await stopTraefikProxy(id);
			await startTraefikProxy(id);
			await prisma.destinationDocker.update({
				where: { id },
				data: { isCoolifyProxyUsed: true }
			});
		}),
	startProxy: privateProcedure
		.input(
			z.object({
				id: z.string()
			})
		)
		.mutation(async ({ input, ctx }) => {
			const { id } = input;
			await startTraefikProxy(id);
		}),
	stopProxy: privateProcedure
		.input(
			z.object({
				id: z.string()
			})
		)
		.mutation(async ({ input, ctx }) => {
			const { id } = input;
			await stopTraefikProxy(id);
		}),
	saveSettings: privateProcedure
		.input(
			z.object({
				id: z.string(),
				engine: z.string(),
				isCoolifyProxyUsed: z.boolean()
			})
		)
		.mutation(async ({ input, ctx }) => {
			const { id, engine, isCoolifyProxyUsed } = input;
			await prisma.destinationDocker.updateMany({
				where: { engine },
				data: { isCoolifyProxyUsed }
			});
		}),
	status: privateProcedure.input(z.object({ id: z.string() })).query(async ({ input, ctx }) => {
		const { id } = input;
		const destination = await prisma.destinationDocker.findUnique({ where: { id } });
		const { found: isRunning } = await checkContainer({
			dockerId: destination.id,
			container: 'coolify-proxy',
			remove: true
		});
		return {
			isRunning
		};
	}),
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
			const { teamId } = ctx.user;
			let {
				id,
				name,
				network,
				engine,
				isCoolifyProxyUsed,
				remoteIpAddress,
				remoteUser,
				remotePort
			} = input;
			if (id === 'new') {
				if (engine) {
					const { stdout } = await await executeCommand({
						command: `docker network ls --filter 'name=^${network}$' --format '{{json .}}'`
					});
					if (stdout === '') {
						await await executeCommand({
							command: `docker network create --attachable ${network}`
						});
					}
					await prisma.destinationDocker.create({
						data: { name, teams: { connect: { id: teamId } }, engine, network, isCoolifyProxyUsed }
					});
					const destinations = await prisma.destinationDocker.findMany({ where: { engine } });
					const destination = destinations.find((destination) => destination.network === network);
					if (destinations.length > 0) {
						const proxyConfigured = destinations.find(
							(destination) =>
								destination.network !== network && destination.isCoolifyProxyUsed === true
						);
						if (proxyConfigured) {
							isCoolifyProxyUsed = !!proxyConfigured.isCoolifyProxyUsed;
						}
						await prisma.destinationDocker.updateMany({
							where: { engine },
							data: { isCoolifyProxyUsed }
						});
					}
					if (isCoolifyProxyUsed) {
						await startTraefikProxy(destination.id);
					}
					return { id: destination.id };
				} else {
					const destination = await prisma.destinationDocker.create({
						data: {
							name,
							teams: { connect: { id: teamId } },
							engine,
							network,
							isCoolifyProxyUsed,
							remoteEngine: true,
							remoteIpAddress,
							remoteUser,
							remotePort: Number(remotePort)
						}
					});
					return { id: destination.id };
				}
			} else {
				await prisma.destinationDocker.update({ where: { id }, data: { name, engine, network } });
				return {};
			}
		}),
	check: privateProcedure
		.input(
			z.object({
				network: z.string()
			})
		)
		.query(async ({ input, ctx }) => {
			const { network } = input;
			const found = await prisma.destinationDocker.findFirst({ where: { network } });
			if (found) {
				throw {
					message: `Network already exists: ${network}`
				};
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
			const { network, remoteVerified, engine, isCoolifyProxyUsed } =
				await prisma.destinationDocker.findUnique({ where: { id } });
			if (isCoolifyProxyUsed) {
				if (engine || remoteVerified) {
					const { stdout: found } = await executeCommand({
						dockerId: id,
						command: `docker ps -a --filter network=${network} --filter name=coolify-proxy --format '{{.}}'`
					});
					if (found) {
						await executeCommand({
							dockerId: id,
							command: `docker network disconnect ${network} coolify-proxy`
						});
						await executeCommand({ dockerId: id, command: `docker network rm ${network}` });
					}
				}
			}
			await prisma.destinationDocker.delete({ where: { id } });
		}),
	getDestinationById: privateProcedure
		.input(
			z.object({
				id: z.string()
			})
		)
		.query(async ({ input, ctx }) => {
			const { id } = input;
			const { teamId } = ctx.user;
			const destination = await prisma.destinationDocker.findFirst({
				where: { id, teams: { some: { id: teamId === '0' ? undefined : teamId } } },
				include: { sshKey: true, application: true, service: true, database: true }
			});
			if (!destination && id !== 'new') {
				throw { status: 404, message: `Destination not found.` };
			}
			const settings = await listSettings();
			return {
				destination,
				settings
			};
		})
});
