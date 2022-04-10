import { dev } from '$app/env';
import { sentry } from '$lib/common';
import {
	supportedDatabaseTypesAndVersions,
	supportedServiceTypesAndVersions
} from '$lib/components/common';
import * as Prisma from '@prisma/client';
import { default as ProdPrisma } from '@prisma/client';
import type { PrismaClientOptions } from '@prisma/client/runtime';
import generator from 'generate-password';
import forge from 'node-forge';

export function generatePassword(length = 24) {
	return generator.generate({
		length,
		numbers: true,
		strict: true
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

export function ErrorHandler(e) {
	if (e! instanceof Error) {
		e = new Error(e.toString());
	}
	let truncatedError = e;
	if (e.stdout) {
		truncatedError = e.stdout;
	}
	if (e.message?.includes('docker run')) {
		let truncatedArray = [];
		truncatedArray = truncatedError.message.split('-').filter((line) => {
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
	// console.error(e)
	return payload;
}
export async function generateSshKeyPair(): Promise<{ publicKey: string; privateKey: string }> {
	return await new Promise(async (resolve, reject) => {
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

export function getVersions(type) {
	const found = supportedDatabaseTypesAndVersions.find((t) => t.name === type);
	if (found) {
		return found.versions;
	}
	return [];
}
export function getDatabaseImage(type) {
	const found = supportedDatabaseTypesAndVersions.find((t) => t.name === type);
	if (found) {
		return found.baseImage;
	}
	return '';
}
export function getServiceImage(type) {
	const found = supportedServiceTypesAndVersions.find((t) => t.name === type);
	if (found) {
		return found.baseImage;
	}
	return '';
}
export function getServiceImages(type) {
	const found = supportedServiceTypesAndVersions.find((t) => t.name === type);
	if (found) {
		return found.images;
	}
	return [];
}
export function generateDatabaseConfiguration(database) {
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
			// url: `mysql://${dbUser}:${dbUserPassword}@${id}:${isPublic ? port : 3306}/${defaultDatabase}`,
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
	} else if (type === 'mongodb') {
		return {
			// url: `mongodb://${dbUser}:${dbUserPassword}@${id}:${isPublic ? port : 27017}/${defaultDatabase}`,
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
			// url: `psql://${dbUser}:${dbUserPassword}@${id}:${isPublic ? port : 5432}/${defaultDatabase}`,
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
			// url: `redis://${dbUser}:${dbUserPassword}@${id}:${isPublic ? port : 6379}/${defaultDatabase}`,
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
			// url: `couchdb://${dbUser}:${dbUserPassword}@${id}:${isPublic ? port : 5984}/${defaultDatabase}`,
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
	// } else if (type === 'clickhouse') {
	//     return {
	//         url: `clickhouse://${dbUser}:${dbUserPassword}@${id}:${port}/${defaultDatabase}`,
	//         privatePort: 9000,
	//         image: `bitnami/clickhouse-server:${version}`,
	//         volume: `${id}-${type}-data:/var/lib/clickhouse`,
	//         ulimits: {
	// 			nofile: {
	// 				soft: 262144,
	// 				hard: 262144
	// 			}
	// 		}
	//     }
	// }
}
