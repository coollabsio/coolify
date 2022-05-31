import { asyncExecShell, createDirectories, getEngine, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { promises as fs } from 'fs';
import yaml from 'js-yaml';
import type { RequestHandler } from '@sveltejs/kit';
import { ErrorHandler, getServiceImage } from '$lib/database';
import { makeLabelForServices } from '$lib/buildPacks/common';
import type { ComposeFile } from '$lib/types/composeFile';
import { getServiceMainPort } from '$lib/components/common';

export const post: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { id } = event.params;

	try {
		const service = await db.getService({ id, teamId });
		const { type, version, destinationDockerId, destinationDocker, serviceSecret, exposePort } =
			service;
		const network = destinationDockerId && destinationDocker.network;
		const host = getEngine(destinationDocker.engine);
		const port = getServiceMainPort('n8n');

		const { workdir } = await createDirectories({ repository: type, buildId: id });
		const image = getServiceImage(type);

		if (serviceSecret.length > 0) {
			serviceSecret.forEach((secret) => {
				variables[secret.name] = secret.value;
			});
		}

		const variables = {
			_APP_ENV: 'production',
			_APP_VERSION: '',
			_APP_LOCALE: '',
			_APP_OPTIONS_ABUSE: '',
			_APP_OPTIONS_FORCE_HTTPS: '',
			_APP_OPENSSL_KEY_V1: '',
			_APP_DOMAIN: '',
			_APP_DOMAIN_TARGET: '',
			_APP_CONSOLE_WHITELIST_ROOT: '',
			_APP_CONSOLE_WHITELIST_EMAILS: '',
			_APP_CONSOLE_WHITELIST_IPS: '',
			_APP_SYSTEM_EMAIL_NAME: '',
			_APP_SYSTEM_EMAIL_ADDRESS: '',
			_APP_SYSTEM_RESPONSE_FORMAT: '',
			_APP_SYSTEM_SECURITY_EMAIL_ADDRESS: '',
			_APP_USAGE_STATS: '',
			_APP_LOGGING_PROVIDER: '',
			_APP_LOGGING_CONFIG: '',
			_APP_USAGE_AGGREGATION_INTERVAL: '',
			_APP_WORKER_PER_CORE: '',
			_APP_REDIS_HOST: '',
			_APP_REDIS_PORT: '',
			_APP_REDIS_USER: '',
			_APP_REDIS_PASS: '',
			_APP_DB_HOST: '',
			_APP_DB_PORT: '',
			_APP_DB_SCHEMA: '',
			_APP_DB_USER: '',
			_APP_DB_PASS: '',
			_APP_DB_ROOT_PASS: '',
			_APP_INFLUXDB_HOST: '',
			_APP_INFLUXDB_PORT: '',
			_APP_STATSD_HOST: '',
			_APP_STATSD_PORT: '',
			_APP_SMTP_HOST: '',
			_APP_SMTP_PORT: '',
			_APP_SMTP_SECURE: '',
			_APP_SMTP_USERNAME: '',
			_APP_SMTP_PASSWORD: '',
			_APP_STORAGE_LIMIT: '',
			_APP_STORAGE_ANTIVIRUS: '',
			_APP_STORAGE_ANTIVIRUS_HOST: '',
			_APP_STORAGE_ANTIVIRUS_PORT: '',
			_APP_STORAGE_DEVICE: '',
			_APP_STORAGE_S3_ACCESS_KEY: '',
			_APP_STORAGE_S3_SECRET: '',
			_APP_STORAGE_S3_REGION: '',
			_APP_STORAGE_S3_BUCKET: '',
			_APP_STORAGE_DO_SPACES_ACCESS_KEY: '',
			_APP_STORAGE_DO_SPACES_SECRET: '',
			_APP_STORAGE_DO_SPACES_REGION: '',
			_APP_STORAGE_DO_SPACES_BUCKET: '',
			_APP_FUNCTIONS_SIZE_LIMIT: '',
			_APP_FUNCTIONS_TIMEOUT: '',
			_APP_FUNCTIONS_BUILD_TIMEOUT: '',
			_APP_FUNCTIONS_CONTAINERS: '',
			_APP_FUNCTIONS_CPUS: '',
			_APP_FUNCTIONS_MEMORY: '',
			_APP_FUNCTIONS_MEMORY_SWAP: '',
			_APP_FUNCTIONS_RUNTIMES: '',
			_APP_EXECUTOR_SECRET: '',
			_APP_EXECUTOR_RUNTIME_NETWORK: '',
			_APP_FUNCTIONS_ENVS: '',
			_APP_FUNCTIONS_INACTIVE_THRESHOLD: '',
			DOCKERHUB_PULL_USERNAME: '',
			DOCKERHUB_PULL_PASSWORD: '',
			DOCKERHUB_PULL_EMAIL: '',
			_APP_MAINTENANCE_INTERVAL: '',
			_APP_MAINTENANCE_RETENTION_EXECUTION: '',
			_APP_MAINTENANCE_RETENTION_ABUSE: '',
			_APP_MAINTENANCE_RETENTION_AUDIT: ''
		};
		const config = {
			appwrite: {
				image: `${image}:${version}`,
				volumes: [
					`${id}-appwrite-uploads:/storage/uploads`,
					`${id}-appwrite-cache:/storage/cache`,
					`${id}-appwrite-config:/storage/config`,
					`${id}-appwrite-certificates:/storage/certificates`,
					`${id}-appwrite-functions:/storage/functions`
				],
				environmentVariables: {
					_APP_ENV: variables._APP_ENV,
					_APP_WORKER_PER_CORE: variables._APP_WORKER_PER_CORE,
					_APP_LOCALE: variables._APP_LOCALE,
					_APP_CONSOLE_WHITELIST_ROOT: variables._APP_CONSOLE_WHITELIST_ROOT,
					_APP_CONSOLE_WHITELIST_EMAILS: variables._APP_CONSOLE_WHITELIST_EMAILS,
					_APP_CONSOLE_WHITELIST_IPS: variables._APP_CONSOLE_WHITELIST_IPS,
					_APP_SYSTEM_EMAIL_NAME: variables._APP_SYSTEM_EMAIL_NAME,
					_APP_SYSTEM_EMAIL_ADDRESS: variables._APP_SYSTEM_EMAIL_ADDRESS,
					_APP_SYSTEM_SECURITY_EMAIL_ADDRESS: variables._APP_SYSTEM_SECURITY_EMAIL_ADDRESS,
					_APP_SYSTEM_RESPONSE_FORMAT: variables._APP_SYSTEM_RESPONSE_FORMAT,
					_APP_OPTIONS_ABUSE: variables._APP_OPTIONS_ABUSE,
					_APP_OPTIONS_FORCE_HTTPS: variables._APP_OPTIONS_FORCE_HTTPS,
					_APP_OPENSSL_KEY_V1: variables._APP_OPENSSL_KEY_V1,
					_APP_DOMAIN: variables._APP_DOMAIN,
					_APP_DOMAIN_TARGET: variables._APP_DOMAIN_TARGET,
					_APP_REDIS_HOST: variables._APP_REDIS_HOST,
					_APP_REDIS_PORT: variables._APP_REDIS_PORT,
					_APP_REDIS_USER: variables._APP_REDIS_USER,
					_APP_REDIS_PASS: variables._APP_REDIS_PASS,
					_APP_DB_HOST: variables._APP_DB_HOST,
					_APP_DB_PORT: variables._APP_DB_PORT,
					_APP_DB_SCHEMA: variables._APP_DB_SCHEMA,
					_APP_DB_USER: variables._APP_DB_USER,
					_APP_DB_PASS: variables._APP_DB_PASS,
					_APP_SMTP_HOST: variables._APP_SMTP_HOST,
					_APP_SMTP_PORT: variables._APP_SMTP_PORT,
					_APP_SMTP_SECURE: variables._APP_SMTP_SECURE,
					_APP_SMTP_USERNAME: variables._APP_SMTP_USERNAME,
					_APP_SMTP_PASSWORD: variables._APP_SMTP_PASSWORD,
					_APP_USAGE_STATS: variables._APP_USAGE_STATS,
					_APP_INFLUXDB_HOST: variables._APP_INFLUXDB_HOST,
					_APP_INFLUXDB_PORT: variables._APP_INFLUXDB_PORT,
					_APP_STORAGE_LIMIT: variables._APP_STORAGE_LIMIT,
					_APP_STORAGE_ANTIVIRUS: variables._APP_STORAGE_ANTIVIRUS,
					_APP_STORAGE_ANTIVIRUS_HOST: variables._APP_STORAGE_ANTIVIRUS_HOST,
					_APP_STORAGE_ANTIVIRUS_PORT: variables._APP_STORAGE_ANTIVIRUS_PORT,
					_APP_STORAGE_DEVICE: variables._APP_STORAGE_DEVICE,
					_APP_STORAGE_S3_ACCESS_KEY: variables._APP_STORAGE_S3_ACCESS_KEY,
					_APP_STORAGE_S3_SECRET: variables._APP_STORAGE_S3_SECRET,
					_APP_STORAGE_S3_REGION: variables._APP_STORAGE_S3_REGION,
					_APP_STORAGE_S3_BUCKET: variables._APP_STORAGE_S3_BUCKET,
					_APP_STORAGE_DO_SPACES_ACCESS_KEY: variables._APP_STORAGE_DO_SPACES_ACCESS_KEY,
					_APP_STORAGE_DO_SPACES_SECRET: variables._APP_STORAGE_DO_SPACES_SECRET,
					_APP_STORAGE_DO_SPACES_REGION: variables._APP_STORAGE_DO_SPACES_REGION,
					_APP_STORAGE_DO_SPACES_BUCKET: variables._APP_STORAGE_DO_SPACES_BUCKET,
					_APP_FUNCTIONS_SIZE_LIMIT: variables._APP_FUNCTIONS_SIZE_LIMIT,
					_APP_FUNCTIONS_TIMEOUT: variables._APP_FUNCTIONS_TIMEOUT,
					_APP_FUNCTIONS_BUILD_TIMEOUT: variables._APP_FUNCTIONS_BUILD_TIMEOUT,
					_APP_FUNCTIONS_CONTAINERS: variables._APP_FUNCTIONS_CONTAINERS,
					_APP_FUNCTIONS_CPUS: variables._APP_FUNCTIONS_CPUS,
					_APP_FUNCTIONS_MEMORY: variables._APP_FUNCTIONS_MEMORY,
					_APP_FUNCTIONS_MEMORY_SWAP: variables._APP_FUNCTIONS_MEMORY_SWAP,
					_APP_EXECUTOR_SECRET: variables._APP_EXECUTOR_SECRET,
					_APP_FUNCTIONS_RUNTIMES: variables._APP_FUNCTIONS_RUNTIMES,
					_APP_LOGGING_PROVIDER: variables._APP_LOGGING_PROVIDER,
					_APP_LOGGING_CONFIG: variables._APP_LOGGING_CONFIG,
					_APP_STATSD_HOST: variables._APP_STATSD_HOST,
					_APP_STATSD_PORT: variables._APP_STATSD_PORT,
					_APP_MAINTENANCE_INTERVAL: variables._APP_MAINTENANCE_INTERVAL,
					_APP_MAINTENANCE_RETENTION_EXECUTION: variables._APP_MAINTENANCE_RETENTION_EXECUTION,
					_APP_MAINTENANCE_RETENTION_ABUSE: variables._APP_MAINTENANCE_RETENTION_ABUSE,
					_APP_MAINTENANCE_RETENTION_AUDIT: variables._APP_MAINTENANCE_RETENTION_AUDIT
				}
			},
			appwriteRealtime: {
				image: `${image}:${version}`,
				environmentVariables: {
					_APP_ENV: variables._APP_ENV,
					_APP_WORKER_PER_CORE: variables._APP_WORKER_PER_CORE,
					_APP_OPTIONS_ABUSE: variables._APP_OPTIONS_ABUSE,
					_APP_OPENSSL_KEY_V1: variables._APP_OPENSSL_KEY_V1,
					_APP_REDIS_HOST: variables._APP_REDIS_HOST,
					_APP_REDIS_PORT: variables._APP_REDIS_PORT,
					_APP_DB_HOST: variables._APP_DB_HOST,
					_APP_DB_PORT: variables._APP_DB_PORT,
					_APP_DB_SCHEMA: variables._APP_DB_SCHEMA,
					_APP_DB_USER: variables._APP_DB_USER,
					_APP_DB_PASS: variables._APP_DB_PASS,
					_APP_USAGE_STATS: variables._APP_USAGE_STATS,
					_APP_LOGGING_PROVIDER: variables._APP_LOGGING_PROVIDER,
					_APP_LOGGING_CONFIG: variables._APP_LOGGING_CONFIG
				}
			},
			appwriteExecutor: {
				image: `${image}:${version}`,
				volumes: [
					`${id}-appwrite-functions:/storage/functions`,
					`/tmp:/tmp`,
					'/var/run/docker.sock:/var/run/docker.sock'
				],
				environmentVariables: {
					DOCKERHUB_PULL_USERNAME: variables.DOCKERHUB_PULL_USERNAME,
					DOCKERHUB_PULL_PASSWORD: variables.DOCKERHUB_PULL_PASSWORD,
					_APP_LOGGING_PROVIDER: variables._APP_LOGGING_PROVIDER,
					_APP_LOGGING_CONFIG: variables._APP_LOGGING_CONFIG,
					_APP_VERSION: variables._APP_VERSION,
					_APP_ENV: variables._APP_ENV,
					_APP_STORAGE_DEVICE: variables._APP_STORAGE_DEVICE,
					_APP_STORAGE_S3_ACCESS_KEY: variables._APP_STORAGE_S3_ACCESS_KEY,
					_APP_STORAGE_S3_SECRET: variables._APP_STORAGE_S3_SECRET,
					_APP_STORAGE_S3_REGION: variables._APP_STORAGE_S3_REGION,
					_APP_STORAGE_S3_BUCKET: variables._APP_STORAGE_S3_BUCKET,
					_APP_STORAGE_DO_SPACES_ACCESS_KEY: variables._APP_STORAGE_DO_SPACES_ACCESS_KEY,
					_APP_STORAGE_DO_SPACES_SECRET: variables._APP_STORAGE_DO_SPACES_SECRET,
					_APP_STORAGE_DO_SPACES_REGION: variables._APP_STORAGE_DO_SPACES_REGION,
					_APP_STORAGE_DO_SPACES_BUCKET: variables._APP_STORAGE_DO_SPACES_BUCKET,
					_APP_FUNCTIONS_CPUS: variables._APP_FUNCTIONS_CPUS,
					_APP_FUNCTIONS_MEMORY: variables._APP_FUNCTIONS_MEMORY,
					_APP_FUNCTIONS_MEMORY_SWAP: variables._APP_FUNCTIONS_MEMORY_SWAP,
					_APP_FUNCTIONS_TIMEOUT: variables._APP_FUNCTIONS_TIMEOUT,
					_APP_EXECUTOR_SECRET: variables._APP_EXECUTOR_SECRET,
					_APP_FUNCTIONS_RUNTIMES: variables._APP_FUNCTIONS_RUNTIMES,
					_APP_FUNCTIONS_INACTIVE_THRESHOLD: variables._APP_FUNCTIONS_INACTIVE_THRESHOLD,
					_APP_EXECUTOR_RUNTIME_NETWORK: variables._APP_EXECUTOR_RUNTIME_NETWORK
				}
			},
			appwriteWorkerDatabase: {
				image: `${image}:${version}`,
				environmentVariables: {
					_APP_ENV: variables._APP_ENV,
					_APP_OPENSSL_KEY_V1: variables._APP_OPENSSL_KEY_V1,
					_APP_REDIS_HOST: variables._APP_REDIS_HOST,
					_APP_REDIS_PORT: variables._APP_REDIS_PORT,
					_APP_REDIS_USER: variables._APP_REDIS_USER,
					_APP_REDIS_PASS: variables._APP_REDIS_PASS,
					_APP_DB_HOST: variables._APP_DB_HOST,
					_APP_DB_PORT: variables._APP_DB_PORT,
					_APP_DB_SCHEMA: variables._APP_DB_SCHEMA,
					_APP_DB_USER: variables._APP_DB_USER,
					_APP_DB_PASS: variables._APP_DB_PASS,
					_APP_LOGGING_PROVIDER: variables._APP_LOGGING_PROVIDER,
					_APP_LOGGING_CONFIG: variables._APP_LOGGING_CONFIG
				}
			},
			appwriteWorkerBuilds: {
				image: `${image}:${version}`,
				environmentVariables: {
					_APP_ENV: variables._APP_ENV,
					_APP_OPENSSL_KEY_V1: variables._APP_OPENSSL_KEY_V1,
					_APP_EXECUTOR_SECRET: variables._APP_EXECUTOR_SECRET,
					_APP_REDIS_HOST: variables._APP_REDIS_HOST,
					_APP_REDIS_PORT: variables._APP_REDIS_PORT,
					_APP_REDIS_USER: variables._APP_REDIS_USER,
					_APP_REDIS_PASS: variables._APP_REDIS_PASS,
					_APP_DB_HOST: variables._APP_DB_HOST,
					_APP_DB_PORT: variables._APP_DB_PORT,
					_APP_DB_SCHEMA: variables._APP_DB_SCHEMA,
					_APP_DB_USER: variables._APP_DB_USER,
					_APP_DB_PASS: variables._APP_DB_PASS,
					_APP_LOGGING_PROVIDER: variables._APP_LOGGING_PROVIDER,
					_APP_LOGGING_CONFIG: variables._APP_LOGGING_CONFIG
				}
			},
			appwriteWorkerAudits: {
				image: `${image}:${version}`,
				environmentVariables: {
					_APP_ENV: variables._APP_ENV,
					_APP_OPENSSL_KEY_V1: variables._APP_OPENSSL_KEY_V1,
					_APP_REDIS_HOST: variables._APP_REDIS_HOST,
					_APP_REDIS_PORT: variables._APP_REDIS_PORT,
					_APP_REDIS_USER: variables._APP_REDIS_USER,
					_APP_REDIS_PASS: variables._APP_REDIS_PASS,
					_APP_DB_HOST: variables._APP_DB_HOST,
					_APP_DB_PORT: variables._APP_DB_PORT,
					_APP_DB_SCHEMA: variables._APP_DB_SCHEMA,
					_APP_DB_USER: variables._APP_DB_USER,
					_APP_DB_PASS: variables._APP_DB_PASS,
					_APP_LOGGING_PROVIDER: variables._APP_LOGGING_PROVIDER,
					_APP_LOGGING_CONFIG: variables._APP_LOGGING_CONFIG
				}
			},
			appwriteWorkerWebhooks: {
				image: `${image}:${version}`,
				environmentVariables: {
					_APP_ENV: variables._APP_ENV,
					_APP_OPENSSL_KEY_V1: variables._APP_OPENSSL_KEY_V1,
					_APP_SYSTEM_SECURITY_EMAIL_ADDRESS: variables._APP_SYSTEM_SECURITY_EMAIL_ADDRESS,
					_APP_REDIS_HOST: variables._APP_REDIS_HOST,
					_APP_REDIS_PORT: variables._APP_REDIS_PORT,
					_APP_REDIS_USER: variables._APP_REDIS_USER,
					_APP_REDIS_PASS: variables._APP_REDIS_PASS,
					_APP_LOGGING_PROVIDER: variables._APP_LOGGING_PROVIDER,
					_APP_LOGGING_CONFIG: variables._APP_LOGGING_CONFIG
				}
			},
			appwriteWorkerDeletes: {
				image: `${image}:${version}`,
				volumes: [
					`${id}-appwrite-uploads:/storage/uploads`,
					`${id}-appwrite-cache:/storage/cache`,
					`${id}-appwrite-certificates:/storage/certificates`
				],
				environmentVariables: {
					_APP_ENV: variables._APP_ENV,
					_APP_OPENSSL_KEY_V1: variables._APP_OPENSSL_KEY_V1,
					_APP_REDIS_HOST: variables._APP_REDIS_HOST,
					_APP_REDIS_PORT: variables._APP_REDIS_PORT,
					_APP_REDIS_USER: variables._APP_REDIS_USER,
					_APP_REDIS_PASS: variables._APP_REDIS_PASS,
					_APP_DB_HOST: variables._APP_DB_HOST,
					_APP_DB_PORT: variables._APP_DB_PORT,
					_APP_DB_SCHEMA: variables._APP_DB_SCHEMA,
					_APP_DB_USER: variables._APP_DB_USER,
					_APP_DB_PASS: variables._APP_DB_PASS,
					_APP_STORAGE_DEVICE: variables._APP_STORAGE_DEVICE,
					_APP_STORAGE_S3_ACCESS_KEY: variables._APP_STORAGE_S3_ACCESS_KEY,
					_APP_STORAGE_S3_SECRET: variables._APP_STORAGE_S3_SECRET,
					_APP_STORAGE_S3_REGION: variables._APP_STORAGE_S3_REGION,
					_APP_STORAGE_S3_BUCKET: variables._APP_STORAGE_S3_BUCKET,
					_APP_STORAGE_DO_SPACES_ACCESS_KEY: variables._APP_STORAGE_DO_SPACES_ACCESS_KEY,
					_APP_STORAGE_DO_SPACES_SECRET: variables._APP_STORAGE_DO_SPACES_SECRET,
					_APP_STORAGE_DO_SPACES_REGION: variables._APP_STORAGE_DO_SPACES_REGION,
					_APP_STORAGE_DO_SPACES_BUCKET: variables._APP_STORAGE_DO_SPACES_BUCKET,
					_APP_LOGGING_PROVIDER: variables._APP_LOGGING_PROVIDER,
					_APP_LOGGING_CONFIG: variables._APP_LOGGING_CONFIG
				}
			},
			appwriteWorkerCertificates: {
				image: `${image}:${version}`,
				volumes: [
					`${id}-appwrite-config:/storage/config`,
					`${id}-appwrite-certificates:/storage/certificates`
				],
				environmentVariables: {
					_APP_ENV: variables._APP_ENV,
					_APP_OPENSSL_KEY_V1: variables._APP_OPENSSL_KEY_V1,
					_APP_SYSTEM_SECURITY_EMAIL_ADDRESS: variables._APP_SYSTEM_SECURITY_EMAIL_ADDRESS,
					_APP_REDIS_HOST: variables._APP_REDIS_HOST,
					_APP_REDIS_PORT: variables._APP_REDIS_PORT,
					_APP_REDIS_USER: variables._APP_REDIS_USER,
					_APP_REDIS_PASS: variables._APP_REDIS_PASS,
					_APP_DOMAIN_TARGET: variables._APP_DOMAIN_TARGET,
					_APP_DB_HOST: variables._APP_DB_HOST,
					_APP_DB_PORT: variables._APP_DB_PORT,
					_APP_DB_SCHEMA: variables._APP_DB_SCHEMA,
					_APP_DB_USER: variables._APP_DB_USER,
					_APP_DB_PASS: variables._APP_DB_PASS,
					_APP_LOGGING_PROVIDER: variables._APP_LOGGING_PROVIDER,
					_APP_LOGGING_CONFIG: variables._APP_LOGGING_CONFIG
				}
			},
			appwriteWorkerFunctions: {
				image: `${image}:${version}`,
				envvironmentVariables: {
					_APP_ENV: variables._APP_ENV,
					_APP_OPENSSL_KEY_V1: variables._APP_OPENSSL_KEY_V1,
					_APP_REDIS_HOST: variables._APP_REDIS_HOST,
					_APP_REDIS_PORT: variables._APP_REDIS_PORT,
					_APP_REDIS_USER: variables._APP_REDIS_USER,
					_APP_REDIS_PASS: variables._APP_REDIS_PASS,
					_APP_DB_HOST: variables._APP_DB_HOST,
					_APP_DB_PORT: variables._APP_DB_PORT,
					_APP_DB_SCHEMA: variables._APP_DB_SCHEMA,
					_APP_DB_USER: variables._APP_DB_USER,
					_APP_DB_PASS: variables._APP_DB_PASS,
					_APP_FUNCTIONS_TIMEOUT: variables._APP_FUNCTIONS_TIMEOUT,
					_APP_EXECUTOR_SECRET: variables._APP_EXECUTOR_SECRET,
					_APP_USAGE_STATS: variables._APP_USAGE_STATS,
					DOCKERHUB_PULL_USERNAME: variables.DOCKERHUB_PULL_USERNAME,
					DOCKERHUB_PULL_PASSWORD: variables.DOCKERHUB_PULL_PASSWORD
				}
			},
			appwriteWorkerMails: {
				image: `${image}:${version}`,
				environmentVariables: {
					_APP_ENV: variables._APP_ENV,
					_APP_OPENSSL_KEY_V1: variables._APP_OPENSSL_KEY_V1,
					_APP_SYSTEM_EMAIL_NAME: variables._APP_SYSTEM_EMAIL_NAME,
					_APP_SYSTEM_EMAIL_ADDRESS: variables._APP_SYSTEM_EMAIL_ADDRESS,
					_APP_REDIS_HOST: variables._APP_REDIS_HOST,
					_APP_REDIS_PORT: variables._APP_REDIS_PORT,
					_APP_REDIS_USER: variables._APP_REDIS_USER,
					_APP_REDIS_PASS: variables._APP_REDIS_PASS,
					_APP_SMTP_HOST: variables._APP_SMTP_HOST,
					_APP_SMTP_PORT: variables._APP_SMTP_PORT,
					_APP_SMTP_SECURE: variables._APP_SMTP_SECURE,
					_APP_SMTP_USERNAME: variables._APP_SMTP_USERNAME,
					_APP_SMTP_PASSWORD: variables._APP_SMTP_PASSWORD,
					_APP_LOGGING_PROVIDER: variables._APP_LOGGING_PROVIDER,
					_APP_LOGGING_CONFIG: variables._APP_LOGGING_CONFIG
				}
			},
			appwriteMaintenance: {
				image: `${image}:${version}`,
				environmentVariables: {
					_APP_ENV: variables._APP_ENV,
					_APP_OPENSSL_KEY_V1: variables._APP_OPENSSL_KEY_V1,
					_APP_REDIS_HOST: variables._APP_REDIS_HOST,
					_APP_REDIS_PORT: variables._APP_REDIS_PORT,
					_APP_REDIS_USER: variables._APP_REDIS_USER,
					_APP_REDIS_PASS: variables._APP_REDIS_PASS,
					_APP_MAINTENANCE_INTERVAL: variables._APP_MAINTENANCE_INTERVAL,
					_APP_MAINTENANCE_RETENTION_EXECUTION: variables._APP_MAINTENANCE_RETENTION_EXECUTION,
					_APP_MAINTENANCE_RETENTION_ABUSE: variables._APP_MAINTENANCE_RETENTION_ABUSE,
					_APP_MAINTENANCE_RETENTION_AUDIT: variables._APP_MAINTENANCE_RETENTION_AUDIT
				}
			},
			appwriteUsage: {
				image: `${image}:${version}`,
				environmentVariables: {
					_APP_ENV: variables._APP_ENV,
					_APP_OPENSSL_KEY_V1: variables._APP_OPENSSL_KEY_V1,
					_APP_DB_HOST: variables._APP_DB_HOST,
					_APP_DB_PORT: variables._APP_DB_PORT,
					_APP_DB_SCHEMA: variables._APP_DB_SCHEMA,
					_APP_DB_USER: variables._APP_DB_USER,
					_APP_DB_PASS: variables._APP_DB_PASS,
					_APP_INFLUXDB_HOST: variables._APP_INFLUXDB_HOST,
					_APP_INFLUXDB_PORT: variables._APP_INFLUXDB_PORT,
					_APP_USAGE_AGGREGATION_INTERVAL: variables._APP_USAGE_AGGREGATION_INTERVAL,
					_APP_REDIS_HOST: variables._APP_REDIS_HOST,
					_APP_REDIS_PORT: variables._APP_REDIS_PORT,
					_APP_REDIS_USER: variables._APP_REDIS_USER,
					_APP_REDIS_PASS: variables._APP_REDIS_PASS
				}
			},
			appwriteSchedule: {
				image: `${image}:${version}`,
				environmentVariables: {
					_APP_ENV: variables._APP_ENV,
					_APP_REDIS_HOST: variables._APP_REDIS_HOST,
					_APP_REDIS_PORT: variables._APP_REDIS_PORT,
					_APP_REDIS_USER: variables._APP_REDIS_USER,
					_APP_REDIS_PASS: variables._APP_REDIS_PASS
				}
			},
			mariadb: {
				image: 'mariadb:10.7',
				volumes: [`${id}-appwrite-mariadb:/var/lib/mysql`],
				environmentVariables: {
					MYSQL_ROOT_PASSWORD: variables._APP_DB_ROOT_PASS,
					MYSQL_DATABASE: variables._APP_DB_SCHEMA,
					MYSQL_USER: variables._APP_DB_USER,
					MYSQL_PASSWORD: variables._APP_DB_PASS
				}
			},
			redis: {
				image: 'redis:6.0-alpine3.12',
				volumes: [`${id}-appwrite-redis:/data`]
			},
			influxdb: {
				image: 'appwrite/influxdb:1.0.0',
				volumes: [`${id}-appwrite-influxdb:/var/lib/influxdb`]
			},
			telegraf: {
				image: 'appwrite/telegraf:1.0.0',
				environmentVariables: {
					_APP_INFLUXDB_HOST: variables._APP_INFLUXDB_HOST,
					_APP_INFLUXDB_PORT: variables._APP_INFLUXDB_PORT
				}
			}
		};

		const composeFile: ComposeFile = {
			version: '3.8',
			services: {
				[id]: {
					container_name: id,
					image: config.image,
					networks: [network],
					volumes: [...config.appwrite.volumes],
					environment: config.environmentVariables,
					restart: 'always',
					labels: makeLabelForServices('appwrite'),
					...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
					deploy: {
						restart_policy: {
							condition: 'on-failure',
							delay: '5s',
							max_attempts: 3,
							window: '120s'
						}
					}
				}
			},
			networks: {
				[network]: {
					external: true
				}
			},
			volumes: {
				[config.volume.split(':')[0]]: {
					name: config.volume.split(':')[0]
				}
			}
		};
		const composeFileDestination = `${workdir}/docker-compose.yaml`;
		await fs.writeFile(composeFileDestination, yaml.dump(composeFile));

		try {
			await asyncExecShell(`DOCKER_HOST=${host} docker compose -f ${composeFileDestination} pull`);
			await asyncExecShell(`DOCKER_HOST=${host} docker compose -f ${composeFileDestination} up -d`);
			return {
				status: 200
			};
		} catch (error) {
			return ErrorHandler(error);
		}
	} catch (error) {
		return ErrorHandler(error);
	}
};
