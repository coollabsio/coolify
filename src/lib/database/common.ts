import { dev } from '$app/env';
import * as Prisma from '@prisma/client';
import { default as ProdPrisma } from '@prisma/client';
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
let prismaOptions = {
	rejectOnNotFound: false
};
if (dev) {
	prismaOptions = {
		errorFormat: 'pretty',
		rejectOnNotFound: false,
		log: [
			{
				emit: 'event',
				level: 'query'
			}
		]
	};
}
export const prisma = new PrismaClient(prismaOptions);

export function PrismaErrorHandler(e) {
	const payload = {
		status: e.status || 500,
		body: {
			message: 'Ooops, something is not okay, are you okay?',
			error: e.error || e.message
		}
	};
	if (e.name === 'NotFoundError') {
		payload.status = 404;
	}
	if (e instanceof P.PrismaClientKnownRequestError) {
		if (e.code === 'P2002') {
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

export const supportedDatabaseTypesAndVersions = [
	{
		name: 'mongodb',
		fancyName: 'MongoDB',
		baseImage: 'bitnami/mongodb',
		versions: ['5.0.5', '4.4.11', '4.2.18', '4.0.27']
	},
	{ name: 'mysql', fancyName: 'MySQL', baseImage: 'bitnami/mysql', versions: ['8.0.27', '5.7.36'] },
	{
		name: 'postgresql',
		fancyName: 'PostgreSQL',
		baseImage: 'bitnami/postgresql',
		versions: ['14.1.0', '13.5.0', '12.9.0', '11.14.0', '10.19.0', '9.6.24']
	},
	{
		name: 'redis',
		fancyName: 'Redis',
		baseImage: 'bitnami/redis',
		versions: ['6.2.6', '6.0.16', '5.0.14']
	},
	{ name: 'couchdb', fancyName: 'CouchDB', baseImage: 'bitnami/couchdb', versions: ['3.2.1'] }
];
export const supportedServiceTypesAndVersions = [
	{
		name: 'plausibleanalytics',
		fancyName: 'Plausible Analytics',
		baseImage: 'plausible/analytics',
		versions: ['latest']
	},
	{ name: 'nocodb', fancyName: 'NocoDB', baseImage: 'nocodb/nocodb', versions: ['latest'] },
	{ name: 'minio', fancyName: 'MinIO', baseImage: 'minio/minio', versions: ['latest'] },
	{
		name: 'vscodeserver',
		fancyName: 'VSCode Server',
		baseImage: 'codercom/code-server',
		versions: ['latest']
	},
	{
		name: 'wordpress',
		fancyName: 'Wordpress',
		baseImage: 'wordpress',
		versions: ['latest', 'php8.1', 'php8.0', 'php7.4', 'php7.3']
	}
];

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
export function generateDatabaseConfiguration(database) {
	const { id, dbUser, dbUserPassword, rootUser, rootUserPassword, defaultDatabase, version, type } =
		database;
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
				REDIS_PASSWORD: dbUserPassword
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
