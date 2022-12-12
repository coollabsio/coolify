import { z } from 'zod';
import { privateProcedure, router } from '../trpc';
import { decrypt } from '../../lib/common';
import { prisma } from '../../prisma';
import { executeCommand } from '../../lib/executeCommand';
import { stopDatabaseContainer, stopTcpHttpProxy } from '../../lib/docker';

export const databasesRouter = router({
	status: privateProcedure.input(z.object({ id: z.string() })).query(async ({ ctx, input }) => {
		const id = input.id;
		const teamId = ctx.user?.teamId;

		let isRunning = false;
		const database = await prisma.database.findFirst({
			where: { id, teams: { some: { id: teamId === '0' ? undefined : teamId } } },
			include: { destinationDocker: true, settings: true }
		});
		if (database) {
			const { destinationDockerId, destinationDocker } = database;
			if (destinationDockerId) {
				try {
					const { stdout } = await executeCommand({
						dockerId: destinationDocker.id,
						command: `docker inspect --format '{{json .State}}' ${id}`
					});

					if (JSON.parse(stdout).Running) {
						isRunning = true;
					}
				} catch (error) {
					//
				}
			}
		}
		return {
			isRunning
		};
	}),
	cleanup: privateProcedure.query(async ({ ctx }) => {
		const teamId = ctx.user?.teamId;
		let databases = await prisma.database.findMany({
			where: { teams: { some: { id: teamId === '0' ? undefined : teamId } } },
			include: { settings: true, destinationDocker: true, teams: true }
		});
		for (const database of databases) {
			if (!database?.version) {
				const { id } = database;
				if (database.destinationDockerId) {
					const everStarted = await stopDatabaseContainer(database);
					if (everStarted)
						await stopTcpHttpProxy(id, database.destinationDocker, database.publicPort);
				}
				await prisma.databaseSettings.deleteMany({ where: { databaseId: id } });
				await prisma.databaseSecret.deleteMany({ where: { databaseId: id } });
				await prisma.database.delete({ where: { id } });
			}
		}
		return {};
	}),
	delete: privateProcedure
		.input(z.object({ id: z.string(), force: z.boolean() }))
		.mutation(async ({ ctx, input }) => {
			const { id, force } = input;
			const teamId = ctx.user?.teamId;
			const database = await prisma.database.findFirst({
				where: { id, teams: { some: { id: teamId === '0' ? undefined : teamId } } },
				include: { destinationDocker: true, settings: true }
			});
			if (!force) {
				if (database.dbUserPassword) database.dbUserPassword = decrypt(database.dbUserPassword);
				if (database.rootUserPassword)
					database.rootUserPassword = decrypt(database.rootUserPassword);
				if (database.destinationDockerId) {
					const everStarted = await stopDatabaseContainer(database);
					if (everStarted)
						await stopTcpHttpProxy(id, database.destinationDocker, database.publicPort);
				}
			}
			await prisma.databaseSettings.deleteMany({ where: { databaseId: id } });
			await prisma.databaseSecret.deleteMany({ where: { databaseId: id } });
			await prisma.database.delete({ where: { id } });
			return {};
		})
});
