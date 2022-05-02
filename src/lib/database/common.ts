import { dev } from '$app/env';
import { sentry } from '$lib/common';
import {
	supportedDatabaseTypesAndVersions,
	supportedServiceTypesAndVersions
} from '$lib/components/common';
import * as Prisma from '@prisma/client';
import { default as ProdPrisma } from '@prisma/client';
import type { Database, DatabaseSettings } from '@prisma/client';
import generator from 'generate-password';
import forge from 'node-forge';
import getPort, { portNumbers } from 'get-port';

export function generatePassword(length = 24, symbols = false): string {
	return generator.generate({
		length,
		numbers: true,
		strict: true,
		symbols
	});
}

let { PrismaClient } = Prisma;
let P = Prisma.Prisma;
if (!dev) {
	PrismaClient = ProdPrisma.PrismaClient;
	P = ProdPrisma.Prisma;
}

export const prisma = new PrismaClient({
	errorFormat: 'pretty',
	rejectOnNotFound: false
});

export function ErrorHandler(e: {
	stdout?;
	message?: string;
	status?: number;
	name?: string;
	error?: string;
}): { status: number; body: { message: string; error: string } } {
	if (e && e instanceof Error) {
		e = new Error(e.toString());
	}
	let truncatedError = e;
	if (e.stdout) {
		truncatedError = e.stdout;
	}
	if (e.message?.includes('docker run')) {
		const truncatedArray: string[] = truncatedError.message.split('-').filter((line) => {
			if (!line.startsWith('e ')) {
				return line;
			}
		});
		truncatedError.message = truncatedArray.join('-');
	}
	if (e.message?.includes('git clone')) {
		truncatedError.message = 'git clone failed';
	}
	if (!e.message?.includes('Coolify Proxy is not running')) {
		sentry.captureException(truncatedError);
	}
	const payload = {
		status: truncatedError.status || 500,
		body: {
			message: 'Ooops, something is not okay, are you okay?',
			error: truncatedError.error || truncatedError.message
		}
	};
	if (truncatedError?.name === 'NotFoundError') {
		payload.status = 404;
	}
	if (truncatedError instanceof P.PrismaClientKnownRequestError) {
		if (truncatedError?.code === 'P2002') {
			payload.body.message = 'Already exists. Choose another name.';
		}
	}
	return payload;
}

export async function generateSshKeyPair(): Promise<{ publicKey: string; privateKey: string }> {
	return await new Promise((resolve, reject) => {
		forge.pki.rsa.generateKeyPair({ bits: 4096, workers: -1 }, function (err, keys) {
			if (keys) {
				resolve({
					publicKey: forge.ssh.publicKeyToOpenSSH(keys.publicKey),
					privateKey: forge.ssh.privateKeyToOpenSSH(keys.privateKey)
				});
			} else {
				reject(keys);
			}
		});
	});
}

export function getVersions(type: string): string[] {
	const found = supportedDatabaseTypesAndVersions.find((t) => t.name === type);
	if (found) {
		return found.versions;
	}
	return [];
}

export function getDatabaseImage(type: string): string {
	const found = supportedDatabaseTypesAndVersions.find((t) => t.name === type);
	if (found) {
		return found.baseImage;
	}
	return '';
}

export function getServiceImage(type: string): string {
	const found = supportedServiceTypesAndVersions.find((t) => t.name === type);
	if (found) {
		return found.baseImage;
	}
	return '';
}

export function getServiceImages(type: string): string[] {
	const found = supportedServiceTypesAndVersions.find((t) => t.name === type);
	if (found) {
		return found.images;
	}
	return [];
}

export function generateDatabaseConfiguration(database: Database & { settings: DatabaseSettings }):
	| {
		volume: string;
		image: string;
		ulimits: Record<string, unknown>;
		privatePort: number;
		environmentVariables: {
			MYSQL_DATABASE: string;
			MYSQL_PASSWORD: string;
			MYSQL_ROOT_USER: string;
			MYSQL_USER: string;
			MYSQL_ROOT_PASSWORD: string;
		};
	}
	| {
		volume: string;
		image: string;
		ulimits: Record<string, unknown>;
		privatePort: number;
		environmentVariables: {
			MONGODB_ROOT_USER: string;
			MONGODB_ROOT_PASSWORD: string;
		};
	}
	| {
		volume: string;
		image: string;
		ulimits: Record<string, unknown>;
		privatePort: number;
		environmentVariables: {
			MARIADB_ROOT_USER: string;
			MARIADB_ROOT_PASSWORD: string;
			MARIADB_USER: string;
			MARIADB_PASSWORD: string;
			MARIADB_DATABASE: string;
		};
	}
	| {
		volume: string;
		image: string;
		ulimits: Record<string, unknown>;
		privatePort: number;
		environmentVariables: {
			POSTGRESQL_POSTGRES_PASSWORD: string;
			POSTGRESQL_USERNAME: string;
			POSTGRESQL_PASSWORD: string;
			POSTGRESQL_DATABASE: string;
		};
	}
	| {
		volume: string;
		image: string;
		ulimits: Record<string, unknown>;
		privatePort: number;
		environmentVariables: {
			REDIS_AOF_ENABLED: string;
			REDIS_PASSWORD: string;
		};
	}
	| {
		volume: string;
		image: string;
		ulimits: Record<string, unknown>;
		privatePort: number;
		environmentVariables: {
			COUCHDB_PASSWORD: string;
			COUCHDB_USER: string;
		};
	} {
	const {
		id,
		dbUser,
		dbUserPassword,
		rootUser,
		rootUserPassword,
		defaultDatabase,
		version,
		type,
		settings: { appendOnly }
	} = database;
	const baseImage = getDatabaseImage(type);
	if (type === 'mysql') {
		return {
			privatePort: 3306,
			environmentVariables: {
				MYSQL_USER: dbUser,
				MYSQL_PASSWORD: dbUserPassword,
				MYSQL_ROOT_PASSWORD: rootUserPassword,
				MYSQL_ROOT_USER: rootUser,
				MYSQL_DATABASE: defaultDatabase
			},
			image: `${baseImage}:${version}`,
			volume: `${id}-${type}-data:/bitnami/mysql/data`,
			ulimits: {}
		};
	} else if (type === 'mariadb') {
		return {
			privatePort: 3306,
			environmentVariables: {
				MARIADB_ROOT_USER: rootUser,
				MARIADB_ROOT_PASSWORD: rootUserPassword,
				MARIADB_USER: dbUser,
				MARIADB_PASSWORD: dbUserPassword,
				MARIADB_DATABASE: defaultDatabase
			},
			image: `${baseImage}:${version}`,
			volume: `${id}-${type}-data:/bitnami/mariadb`,
			ulimits: {}
		};
	} else if (type === 'mongodb') {
		return {
			privatePort: 27017,
			environmentVariables: {
				MONGODB_ROOT_USER: rootUser,
				MONGODB_ROOT_PASSWORD: rootUserPassword
			},
			image: `${baseImage}:${version}`,
			volume: `${id}-${type}-data:/bitnami/mongodb`,
			ulimits: {}
		};
	} else if (type === 'postgresql') {
		return {
			privatePort: 5432,
			environmentVariables: {
				POSTGRESQL_POSTGRES_PASSWORD: rootUserPassword,
				POSTGRESQL_PASSWORD: dbUserPassword,
				POSTGRESQL_USERNAME: dbUser,
				POSTGRESQL_DATABASE: defaultDatabase
			},
			image: `${baseImage}:${version}`,
			volume: `${id}-${type}-data:/bitnami/postgresql`,
			ulimits: {}
		};
	} else if (type === 'redis') {
		return {
			privatePort: 6379,
			environmentVariables: {
				REDIS_PASSWORD: dbUserPassword,
				REDIS_AOF_ENABLED: appendOnly ? 'yes' : 'no'
			},
			image: `${baseImage}:${version}`,
			volume: `${id}-${type}-data:/bitnami/redis/data`,
			ulimits: {}
		};
	} else if (type === 'couchdb') {
		return {
			privatePort: 5984,
			environmentVariables: {
				COUCHDB_PASSWORD: dbUserPassword,
				COUCHDB_USER: dbUser
			},
			image: `${baseImage}:${version}`,
			volume: `${id}-${type}-data:/bitnami/couchdb`,
			ulimits: {}
		};
	}
}

export async function getFreePort() {
	const data = await prisma.setting.findFirst();
	const { minPort, maxPort } = data;

	const dbUsed = await (
		await prisma.database.findMany({
			where: { publicPort: { not: null } },
			select: { publicPort: true }
		})
	).map((a) => a.publicPort);
	const wpFtpUsed = await (
		await prisma.wordpress.findMany({
			where: { ftpPublicPort: { not: null } },
			select: { ftpPublicPort: true }
		})
	).map((a) => a.ftpPublicPort);
	const wpUsed = await (
		await prisma.wordpress.findMany({
			where: { mysqlPublicPort: { not: null } },
			select: { mysqlPublicPort: true }
		})
	).map((a) => a.mysqlPublicPort);
	const usedPorts = [...dbUsed, ...wpFtpUsed, ...wpUsed];
	return await getPort({ port: portNumbers(minPort, maxPort), exclude: usedPorts });
}
