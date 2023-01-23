import { base64Encode, isARM, version } from "../../../lib/common";
import { executeCommand } from "../../../lib/executeCommand";
import { prisma } from "../../../prisma";

export const supportedDatabaseTypesAndVersions = [
	{
		name: 'mongodb',
		fancyName: 'MongoDB',
		baseImage: 'bitnami/mongodb',
		baseImageARM: 'mongo',
		versions: ['5.0', '4.4', '4.2'],
		versionsARM: ['5.0', '4.4', '4.2']
	},
	{
		name: 'mysql',
		fancyName: 'MySQL',
		baseImage: 'bitnami/mysql',
		baseImageARM: 'mysql',
		versions: ['8.0', '5.7'],
		versionsARM: ['8.0', '5.7']
	},
	{
		name: 'mariadb',
		fancyName: 'MariaDB',
		baseImage: 'bitnami/mariadb',
		baseImageARM: 'mariadb',
		versions: ['10.8', '10.7', '10.6', '10.5', '10.4', '10.3', '10.2'],
		versionsARM: ['10.8', '10.7', '10.6', '10.5', '10.4', '10.3', '10.2']
	},
	{
		name: 'postgresql',
		fancyName: 'PostgreSQL',
		baseImage: 'bitnami/postgresql',
		baseImageARM: 'postgres',
		versions: ['14.5.0', '13.8.0', '12.12.0', '11.17.0', '10.22.0'],
		versionsARM: ['14.5', '13.8', '12.12', '11.17', '10.22']
	},
	{
		name: 'redis',
		fancyName: 'Redis',
		baseImage: 'bitnami/redis',
		baseImageARM: 'redis',
		versions: ['7.0', '6.2', '6.0', '5.0'],
		versionsARM: ['7.0', '6.2', '6.0', '5.0']
	},
	{
		name: 'couchdb',
		fancyName: 'CouchDB',
		baseImage: 'bitnami/couchdb',
		baseImageARM: 'couchdb',
		versions: ['3.2.2', '3.1.2', '2.3.1'],
		versionsARM: ['3.2.2', '3.1.2', '2.3.1']
	},
	{
		name: 'edgedb',
		fancyName: 'EdgeDB',
		baseImage: 'edgedb/edgedb',
		versions: ['latest', '2.1', '2.0', '1.4']
	}
];
export function getDatabaseImage(type: string, arch: string): string {
	const found = supportedDatabaseTypesAndVersions.find((t) => t.name === type);
	if (found) {
		if (isARM(arch)) {
			return found.baseImageARM || found.baseImage;
		}
		return found.baseImage;
	}
	return '';
}
export function generateDatabaseConfiguration(database: any, arch: string) {
	const { id, dbUser, dbUserPassword, rootUser, rootUserPassword, defaultDatabase, version, type } =
		database;
	const baseImage = getDatabaseImage(type, arch);
	if (type === 'mysql') {
		const configuration = {
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
		if (isARM(arch)) {
			configuration.volume = `${id}-${type}-data:/var/lib/mysql`;
		}
		return configuration;
	} else if (type === 'mariadb') {
		const configuration = {
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
		if (isARM(arch)) {
			configuration.volume = `${id}-${type}-data:/var/lib/mysql`;
		}
		return configuration;
	} else if (type === 'mongodb') {
		const configuration = {
			privatePort: 27017,
			environmentVariables: {
				MONGODB_ROOT_USER: rootUser,
				MONGODB_ROOT_PASSWORD: rootUserPassword
			},
			image: `${baseImage}:${version}`,
			volume: `${id}-${type}-data:/bitnami/mongodb`,
			ulimits: {}
		};
		if (isARM(arch)) {
			configuration.environmentVariables = {
				MONGO_INITDB_ROOT_USERNAME: rootUser,
				MONGO_INITDB_ROOT_PASSWORD: rootUserPassword
			};
			configuration.volume = `${id}-${type}-data:/data/db`;
		}
		return configuration;
	} else if (type === 'postgresql') {
		const configuration = {
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
		if (isARM(arch)) {
			configuration.volume = `${id}-${type}-data:/var/lib/postgresql`;
			configuration.environmentVariables = {
				POSTGRES_PASSWORD: dbUserPassword,
				POSTGRES_USER: dbUser,
				POSTGRES_DB: defaultDatabase
			};
		}
		return configuration;
	} else if (type === 'redis') {
		const {
			settings: { appendOnly }
		} = database;
		const configuration = {
			privatePort: 6379,
			command: undefined,
			environmentVariables: {
				REDIS_PASSWORD: dbUserPassword,
				REDIS_AOF_ENABLED: appendOnly ? 'yes' : 'no'
			},
			image: `${baseImage}:${version}`,
			volume: `${id}-${type}-data:/bitnami/redis/data`,
			ulimits: {}
		};
		if (isARM(arch)) {
			configuration.volume = `${id}-${type}-data:/data`;
			configuration.command = `/usr/local/bin/redis-server --appendonly ${
				appendOnly ? 'yes' : 'no'
			} --requirepass ${dbUserPassword}`;
		}
		return configuration;
	} else if (type === 'couchdb') {
		const configuration = {
			privatePort: 5984,
			environmentVariables: {
				COUCHDB_PASSWORD: dbUserPassword,
				COUCHDB_USER: dbUser
			},
			image: `${baseImage}:${version}`,
			volume: `${id}-${type}-data:/bitnami/couchdb`,
			ulimits: {}
		};
		if (isARM(arch)) {
			configuration.volume = `${id}-${type}-data:/opt/couchdb/data`;
		}
		return configuration;
	} else if (type === 'edgedb') {
		const configuration = {
			privatePort: 5656,
			environmentVariables: {
				EDGEDB_SERVER_PASSWORD: rootUserPassword,
				EDGEDB_SERVER_USER: rootUser,
				EDGEDB_SERVER_DATABASE: defaultDatabase,
				EDGEDB_SERVER_TLS_CERT_MODE: 'generate_self_signed'
			},
			image: `${baseImage}:${version}`,
			volume: `${id}-${type}-data:/var/lib/edgedb/data`,
			ulimits: {}
		};
		return configuration;
	}
    return null;
}
export function getDatabaseVersions(type: string, arch: string): string[] {
	const found = supportedDatabaseTypesAndVersions.find((t) => t.name === type);
	if (found) {
		if (isARM(arch)) {
			return found.versionsARM || found.versions;
		}
		return found.versions;
	}
	return [];
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
		destinationDocker: { id: dockerId }
	} = database;
	if (destinationDockerId) {
		if (type === 'mysql') {
			await executeCommand({
				dockerId,
				command: `docker exec ${id} mysql -u ${rootUser} -p${rootUserPassword} -e \"ALTER USER '${user}'@'%' IDENTIFIED WITH caching_sha2_password BY '${newPassword}';\"`
			});
		} else if (type === 'mariadb') {
			await executeCommand({
				dockerId,
				command: `docker exec ${id} mysql -u ${rootUser} -p${rootUserPassword} -e \"SET PASSWORD FOR '${user}'@'%' = PASSWORD('${newPassword}');\"`
			});
		} else if (type === 'postgresql') {
			if (isRoot) {
				await executeCommand({
					dockerId,
					command: `docker exec ${id} psql postgresql://postgres:${rootUserPassword}@${id}:5432/${defaultDatabase} -c "ALTER role postgres WITH PASSWORD '${newPassword}'"`
				});
			} else {
				await executeCommand({
					dockerId,
					command: `docker exec ${id} psql postgresql://${dbUser}:${dbUserPassword}@${id}:5432/${defaultDatabase} -c "ALTER role ${user} WITH PASSWORD '${newPassword}'"`
				});
			}
		} else if (type === 'mongodb') {
			await executeCommand({
				dockerId,
				command: `docker exec ${id} mongo 'mongodb://${rootUser}:${rootUserPassword}@${id}:27017/admin?readPreference=primary&ssl=false' --eval "db.changeUserPassword('${user}','${newPassword}')"`
			});
		} else if (type === 'redis') {
			await executeCommand({
				dockerId,
				command: `docker exec ${id} redis-cli -u redis://${dbUserPassword}@${id}:6379 --raw CONFIG SET requirepass ${newPassword}`
			});
		}
	}
}
export async function makeLabelForStandaloneDatabase({ id, image, volume }) {
	const database = await prisma.database.findFirst({ where: { id } });
	delete database.destinationDockerId;
	delete database.createdAt;
	delete database.updatedAt;
	return [
		'coolify.managed=true',
		`coolify.version=${version}`,
		`coolify.type=standalone-database`,
		`coolify.name=${database.name}`,
		`coolify.configuration=${base64Encode(
			JSON.stringify({
				version,
				image,
				volume,
				...database
			})
		)}`
	];
}