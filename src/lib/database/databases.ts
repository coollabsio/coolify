import { decrypt, encrypt } from '$lib/crypto';
import * as db from '$lib/database';
import cuid from 'cuid';
import { generatePassword } from '.';
import { prisma, ErrorHandler } from './common';
import getPort, { portNumbers } from 'get-port';
import { asyncExecShell, getEngine, removeContainer } from '$lib/common';

export async function listDatabases(teamId) {
	if (teamId === '0') {
		return await prisma.database.findMany({ include: { teams: true } });
	} else {
		return await prisma.database.findMany({
			where: { teams: { some: { id: teamId } } },
			include: { teams: true }
		});
	}
}
export async function newDatabase({ name, teamId }) {
	const dbUser = cuid();
	const dbUserPassword = encrypt(generatePassword());
	const rootUser = cuid();
	const rootUserPassword = encrypt(generatePassword());
	const defaultDatabase = cuid();

	return await prisma.database.create({
		data: {
			name,
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
	let body = {};
	if (teamId === '0') {
		body = await prisma.database.findFirst({
			where: { id },
			include: { destinationDocker: true, settings: true }
		});
	} else {
		body = await prisma.database.findFirst({
			where: { id, teams: { some: { id: teamId } } },
			include: { destinationDocker: true, settings: true }
		});
	}

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
	isPublic,
	appendOnly
}: {
	id: string;
	version?: string;
	isPublic?: boolean;
	appendOnly?: boolean;
}) {
	return await prisma.database.update({
		where: { id },
		data: {
			version,
			settings: { upsert: { update: { isPublic, appendOnly }, create: { isPublic, appendOnly } } }
		}
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
			//
		}
	}
	return everStarted;
}

export async function updatePasswordInDb(database, user, newPassword, isRoot) {
	const {
		id,
		type,
		rootUser,
		rootUserPassword,
		dbUser,
		dbUserPassword,
		defaultDatabase,
		destinationDockerId,
		destinationDocker: { engine }
	} = database;
	if (destinationDockerId) {
		const host = getEngine(engine);
		if (type === 'mysql') {
			await asyncExecShell(
				`DOCKER_HOST=${host} docker exec ${id} mysql -u ${rootUser} -p${rootUserPassword} -e \"ALTER USER '${user}'@'%' IDENTIFIED WITH caching_sha2_password BY '${newPassword}';\"`
			);
		} else if (type === 'postgresql') {
			if (isRoot) {
				await asyncExecShell(
					`DOCKER_HOST=${host} docker exec ${id} psql postgresql://postgres:${rootUserPassword}@${id}:5432/${defaultDatabase} -c "ALTER role postgres WITH PASSWORD '${newPassword}'"`
				);
			} else {
				await asyncExecShell(
					`DOCKER_HOST=${host} docker exec ${id} psql postgresql://${dbUser}:${dbUserPassword}@${id}:5432/${defaultDatabase} -c "ALTER role ${user} WITH PASSWORD '${newPassword}'"`
				);
			}
		} else if (type === 'mongodb') {
			await asyncExecShell(
				`DOCKER_HOST=${host} docker exec ${id} mongo 'mongodb://${rootUser}:${rootUserPassword}@${id}:27017/admin?readPreference=primary&ssl=false' --eval "db.changeUserPassword('${user}','${newPassword}')"`
			);
		} else if (type === 'redis') {
			await asyncExecShell(
				`DOCKER_HOST=${host} docker exec ${id} redis-cli -u redis://${dbUserPassword}@${id}:6379 --raw CONFIG SET requirepass ${newPassword}`
			);
		}
	}
}
