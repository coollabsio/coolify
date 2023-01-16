import { z } from 'zod';
import fs from 'fs/promises';
import { privateProcedure, router } from '../../trpc';
import {
	createDirectories,
	decrypt,
	encrypt,
	getContainerUsage,
	listSettings,
	startTraefikTCPProxy
} from '../../../lib/common';
import { prisma } from '../../../prisma';
import { executeCommand } from '../../../lib/executeCommand';
import {
	defaultComposeConfiguration,
	stopDatabaseContainer,
	stopTcpHttpProxy
} from '../../../lib/docker';
import {
	generateDatabaseConfiguration,
	getDatabaseVersions,
	makeLabelForStandaloneDatabase,
	updatePasswordInDb
} from './lib';
import yaml from 'js-yaml';
import { getFreePublicPort } from '../services/lib';

export const databasesRouter = router({
	usage: privateProcedure
		.input(
			z.object({
				id: z.string()
			})
		)
		.query(async ({ ctx, input }) => {
			const teamId = ctx.user?.teamId;
			const { id } = input;
			let usage = {};

			const database = await prisma.database.findFirst({
				where: { id, teams: { some: { id: teamId === '0' ? undefined : teamId } } },
				include: { destinationDocker: true, settings: true }
			});
			if (database.dbUserPassword) database.dbUserPassword = decrypt(database.dbUserPassword);
			if (database.rootUserPassword) database.rootUserPassword = decrypt(database.rootUserPassword);
			if (database.destinationDockerId) {
				[usage] = await Promise.all([getContainerUsage(database.destinationDocker.id, id)]);
			}
			return {
				success: true,
				data: {
					usage
				}
			};
		}),
	save: privateProcedure
		.input(
			z.object({
				id: z.string()
			})
		)
		.mutation(async ({ ctx, input }) => {
			const teamId = ctx.user?.teamId;
			const {
				id,
				name,
				defaultDatabase,
				dbUser,
				dbUserPassword,
				rootUser,
				rootUserPassword,
				version,
				isRunning
			} = input;
			const database = await prisma.database.findFirst({
				where: { id, teams: { some: { id: teamId === '0' ? undefined : teamId } } },
				include: { destinationDocker: true, settings: true }
			});
			if (database.dbUserPassword) database.dbUserPassword = decrypt(database.dbUserPassword);
			if (database.rootUserPassword) database.rootUserPassword = decrypt(database.rootUserPassword);
			if (isRunning) {
				if (database.dbUserPassword !== dbUserPassword) {
					await updatePasswordInDb(database, dbUser, dbUserPassword, false);
				} else if (database.rootUserPassword !== rootUserPassword) {
					await updatePasswordInDb(database, rootUser, rootUserPassword, true);
				}
			}
			const encryptedDbUserPassword = dbUserPassword && encrypt(dbUserPassword);
			const encryptedRootUserPassword = rootUserPassword && encrypt(rootUserPassword);
			await prisma.database.update({
				where: { id },
				data: {
					name,
					defaultDatabase,
					dbUser,
					dbUserPassword: encryptedDbUserPassword,
					rootUser,
					rootUserPassword: encryptedRootUserPassword,
					version
				}
			});
		}),
	saveSettings: privateProcedure
		.input(
			z.object({
				id: z.string(),
				isPublic: z.boolean(),
				appendOnly: z.boolean().default(true)
			})
		)
		.mutation(async ({ ctx, input }) => {
			const teamId = ctx.user?.teamId;
			const { id, isPublic, appendOnly = true } = input;

			let publicPort = null;

			const {
				destinationDocker: { remoteEngine, engine, remoteIpAddress }
			} = await prisma.database.findUnique({ where: { id }, include: { destinationDocker: true } });

			if (isPublic) {
				publicPort = await getFreePublicPort({ id, remoteEngine, engine, remoteIpAddress });
			}
			await prisma.database.update({
				where: { id },
				data: {
					settings: {
						upsert: { update: { isPublic, appendOnly }, create: { isPublic, appendOnly } }
					}
				}
			});
			const database = await prisma.database.findFirst({
				where: { id, teams: { some: { id: teamId === '0' ? undefined : teamId } } },
				include: { destinationDocker: true, settings: true }
			});
			const { arch } = await listSettings();
			if (database.dbUserPassword) database.dbUserPassword = decrypt(database.dbUserPassword);
			if (database.rootUserPassword) database.rootUserPassword = decrypt(database.rootUserPassword);

			const { destinationDockerId, destinationDocker, publicPort: oldPublicPort } = database;
			const { privatePort } = generateDatabaseConfiguration(database, arch);

			if (destinationDockerId) {
				if (isPublic) {
					await prisma.database.update({ where: { id }, data: { publicPort } });
					await startTraefikTCPProxy(destinationDocker, id, publicPort, privatePort);
				} else {
					await prisma.database.update({ where: { id }, data: { publicPort: null } });
					await stopTcpHttpProxy(id, destinationDocker, oldPublicPort);
				}
			}
			return { publicPort };
		}),
	saveSecret: privateProcedure
		.input(
			z.object({
				id: z.string(),
				name: z.string(),
				value: z.string(),
				isNew: z.boolean().default(true)
			})
		)
		.mutation(async ({ ctx, input }) => {
			let { id, name, value, isNew } = input;

			if (isNew) {
				const found = await prisma.databaseSecret.findFirst({ where: { name, databaseId: id } });
				if (found) {
					throw `Secret ${name} already exists.`;
				} else {
					value = encrypt(value.trim());
					await prisma.databaseSecret.create({
						data: { name, value, database: { connect: { id } } }
					});
				}
			} else {
				value = encrypt(value.trim());
				const found = await prisma.databaseSecret.findFirst({ where: { databaseId: id, name } });

				if (found) {
					await prisma.databaseSecret.updateMany({
						where: { databaseId: id, name },
						data: { value }
					});
				} else {
					await prisma.databaseSecret.create({
						data: { name, value, database: { connect: { id } } }
					});
				}
			}
		}),
	start: privateProcedure.input(z.object({ id: z.string() })).mutation(async ({ ctx, input }) => {
		const { id } = input;
		const teamId = ctx.user?.teamId;
		const database = await prisma.database.findFirst({
			where: { id, teams: { some: { id: teamId === '0' ? undefined : teamId } } },
			include: { destinationDocker: true, settings: true, databaseSecret: true }
		});
		const { arch } = await listSettings();
		if (database.dbUserPassword) database.dbUserPassword = decrypt(database.dbUserPassword);
		if (database.rootUserPassword) database.rootUserPassword = decrypt(database.rootUserPassword);
		const {
			type,
			destinationDockerId,
			destinationDocker,
			publicPort,
			settings: { isPublic },
			databaseSecret
		} = database;
		const { privatePort, command, environmentVariables, image, volume, ulimits } =
			generateDatabaseConfiguration(database, arch);

		const network = destinationDockerId && destinationDocker.network;
		const volumeName = volume.split(':')[0];
		const labels = await makeLabelForStandaloneDatabase({ id, image, volume });

		const { workdir } = await createDirectories({ repository: type, buildId: id });
		if (databaseSecret.length > 0) {
			databaseSecret.forEach((secret) => {
				environmentVariables[secret.name] = decrypt(secret.value);
			});
		}
		const composeFile = {
			version: '3.8',
			services: {
				[id]: {
					container_name: id,
					image,
					command,
					environment: environmentVariables,
					volumes: [volume],
					ulimits,
					labels,
					...defaultComposeConfiguration(network)
				}
			},
			networks: {
				[network]: {
					external: true
				}
			},
			volumes: {
				[volumeName]: {
					name: volumeName
				}
			}
		};
		const composeFileDestination = `${workdir}/docker-compose.yaml`;
		await fs.writeFile(composeFileDestination, yaml.dump(composeFile));
		await executeCommand({
			dockerId: destinationDocker.id,
			command: `docker compose -f ${composeFileDestination} up -d`
		});
		if (isPublic) await startTraefikTCPProxy(destinationDocker, id, publicPort, privatePort);
	}),
	stop: privateProcedure.input(z.object({ id: z.string() })).mutation(async ({ ctx, input }) => {
		const { id } = input;
		const teamId = ctx.user?.teamId;
		const database = await prisma.database.findFirst({
			where: { id, teams: { some: { id: teamId === '0' ? undefined : teamId } } },
			include: { destinationDocker: true, settings: true }
		});
		if (database.dbUserPassword) database.dbUserPassword = decrypt(database.dbUserPassword);
		if (database.rootUserPassword) database.rootUserPassword = decrypt(database.rootUserPassword);
		const everStarted = await stopDatabaseContainer(database);
		if (everStarted) await stopTcpHttpProxy(id, database.destinationDocker, database.publicPort);
		await prisma.database.update({
			where: { id },
			data: {
				settings: { upsert: { update: { isPublic: false }, create: { isPublic: false } } }
			}
		});
		await prisma.database.update({ where: { id }, data: { publicPort: null } });
	}),
	getDatabaseById: privateProcedure
		.input(z.object({ id: z.string() }))
		.query(async ({ ctx, input }) => {
			const { id } = input;
			const teamId = ctx.user?.teamId;
			const database = await prisma.database.findFirst({
				where: { id, teams: { some: { id: teamId === '0' ? undefined : teamId } } },
				include: { destinationDocker: true, settings: true }
			});
			if (!database) {
				throw { status: 404, message: 'Database not found.' };
			}
			const settings = await listSettings();
			if (database.dbUserPassword) database.dbUserPassword = decrypt(database.dbUserPassword);
			if (database.rootUserPassword) database.rootUserPassword = decrypt(database.rootUserPassword);
			const configuration = generateDatabaseConfiguration(database, settings.arch);
			return {
				success: true,
				data: {
					privatePort: configuration?.privatePort,
					database,
					versions: await getDatabaseVersions(database.type, settings.arch),
					settings
				}
			};
		}),
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
			success: true,
			data: {
				isRunning
			}
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
		.input(z.object({ id: z.string(), force: z.boolean().default(false) }))
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
