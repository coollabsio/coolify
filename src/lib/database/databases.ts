import { decrypt, encrypt } from '$lib/crypto';
import { dockerInstance } from '$lib/docker';
import cuid from 'cuid';
import { generatePassword } from '.';
import { prisma, PrismaErrorHandler } from './common';
import getPort from 'get-port';
import { asyncExecShell, getEngine, removeContainer } from '$lib/common';

export async function listDatabases(teamId) {
	return await prisma.database.findMany({ where: { teams: { some: { id: teamId } } } });
}
export async function newDatabase({ name, teamId }) {
	const dbUser = cuid();
	const dbUserPassword = encrypt(generatePassword());
	const rootUser = cuid();
	const rootUserPassword = encrypt(generatePassword());
	const defaultDatabase = cuid();

	let publicPort = await getPort();
	let i = 0;

	do {
		const usedPorts = await prisma.database.findMany({ where: { publicPort } });
		if (usedPorts.length === 0) break;
		publicPort = await getPort();
		i++;
	} while (i < 10);
	if (i === 9) {
		throw {
			error: 'No free port found!? Is it possible?'
		};
	}
	return await prisma.database.create({
		data: {
			name,
			publicPort,
			defaultDatabase,
			dbUser,
			dbUserPassword,
			rootUser,
			rootUserPassword,
			teams: { connect: { id: teamId } },
			settings: { create: { isPublic: false } }
		}
	});
}

export async function getDatabase({ id, teamId }) {
	const body = await prisma.database.findFirst({
		where: { id, teams: { some: { id: teamId } } },
		include: { destinationDocker: true, settings: true }
	});

	if (body.dbUserPassword) body.dbUserPassword = decrypt(body.dbUserPassword);
	if (body.rootUserPassword) body.rootUserPassword = decrypt(body.rootUserPassword);

	return { ...body };
}

export async function removeDatabase({ id }) {
	await prisma.databaseSettings.deleteMany({ where: { databaseId: id } });
	await prisma.database.delete({ where: { id } });
	return;
}

export async function configureDatabaseType({ id, type }) {
	return await prisma.database.update({
		where: { id },
		data: { type }
	});
}
export async function setDatabase({
	id,
	version,
	isPublic
}: {
	id: string;
	version?: string;
	isPublic?: boolean;
}) {
	return await prisma.database.update({
		where: { id },
		data: { version, settings: { upsert: { update: { isPublic }, create: { isPublic } } } }
	});
}
export async function updateDatabase({
	id,
	name,
	defaultDatabase,
	dbUser,
	dbUserPassword,
	rootUser,
	rootUserPassword,
	version
}) {
	const encryptedDbUserPassword = dbUserPassword && encrypt(dbUserPassword);
	const encryptedRootUserPassword = rootUserPassword && encrypt(rootUserPassword);
	return await prisma.database.update({
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
}

// export async function setDatabaseSettings({ id, isPublic }) {
//     try {
//         await prisma.databaseSettings.update({ where: { databaseId: id }, data: { isPublic } })
//         return { status: 201 }
//     } catch (e) {
//         throw PrismaErrorHandler(e)
//     }
// }

export async function stopDatabase(database) {
	let everStarted = false;
	const {
		id,
		destinationDockerId,
		destinationDocker: { engine }
	} = database;
	if (destinationDockerId) {
		try {
			const host = getEngine(engine);
			const { stdout } = await asyncExecShell(
				`DOCKER_HOST=${host} docker inspect --format '{{json .State}}' ${id}`
			);
			if (stdout) {
				everStarted = true;
				await removeContainer(id, engine);
			}
		} catch (error) {
			console.log(error);
		}
	}
	return everStarted;
}
