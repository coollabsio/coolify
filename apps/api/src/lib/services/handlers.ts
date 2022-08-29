import type { FastifyReply, FastifyRequest } from 'fastify';
import fs from 'fs/promises';
import yaml from 'js-yaml';
import bcrypt from 'bcryptjs';
import { ServiceStartStop } from '../../routes/api/v1/services/types';
import { asyncSleep, ComposeFile, createDirectories, defaultComposeConfiguration, errorHandler, executeDockerCmd, getDomain, getFreePublicPort, getServiceFromDB, getServiceImage, getServiceMainPort, isARM, makeLabelForServices, persistentVolumes, prisma } from '../common';
import { defaultServiceConfigurations } from '../services';

export async function startService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { type } = request.params
        if (type === 'plausibleanalytics') {
            return await startPlausibleAnalyticsService(request)
        }
        if (type === 'nocodb') {
            return await startNocodbService(request)
        }
        if (type === 'minio') {
            return await startMinioService(request)
        }
        if (type === 'vscodeserver') {
            return await startVscodeService(request)
        }
        if (type === 'wordpress') {
            return await startWordpressService(request)
        }
        if (type === 'vaultwarden') {
            return await startVaultwardenService(request)
        }
        if (type === 'languagetool') {
            return await startLanguageToolService(request)
        }
        if (type === 'n8n') {
            return await startN8nService(request)
        }
        if (type === 'uptimekuma') {
            return await startUptimekumaService(request)
        }
        if (type === 'ghost') {
            return await startGhostService(request)
        }
        if (type === 'meilisearch') {
            return await startMeilisearchService(request)
        }
        if (type === 'umami') {
            return await startUmamiService(request)
        }
        if (type === 'hasura') {
            return await startHasuraService(request)
        }
        if (type === 'fider') {
            return await startFiderService(request)
        }
        if (type === 'moodle') {
            return await startMoodleService(request)
        }
        if (type === 'appwrite') {
            return await startAppWriteService(request)
        }
        if (type === 'glitchTip') {
            return await startGlitchTipService(request)
        }
        if (type === 'searxng') {
            return await startSearXNGService(request)
        }
        throw `Service type ${type} not supported.`
    } catch (error) {
        throw { status: 500, message: error?.message || error }
    }
}
export async function stopService(request: FastifyRequest<ServiceStartStop>) {
    try {
        return await stopServiceContainers(request)
    } catch (error) {
        throw { status: 500, message: error?.message || error }
    }
}

async function startPlausibleAnalyticsService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const {
            type,
            version,
            fqdn,
            destinationDockerId,
            destinationDocker,
            serviceSecret,
            persistentStorage,
            exposePort,
            plausibleAnalytics: {
                id: plausibleDbId,
                username,
                email,
                password,
                postgresqlDatabase,
                postgresqlPassword,
                postgresqlUser,
                secretKeyBase
            }
        } = service;
        const image = getServiceImage(type);

        const config = {
            plausibleAnalytics: {
                image: `${image}:${version}`,
                environmentVariables: {
                    ADMIN_USER_EMAIL: email,
                    ADMIN_USER_NAME: username,
                    ADMIN_USER_PWD: password,
                    BASE_URL: fqdn,
                    SECRET_KEY_BASE: secretKeyBase,
                    DISABLE_AUTH: 'false',
                    DISABLE_REGISTRATION: 'true',
                    DATABASE_URL: `postgresql://${postgresqlUser}:${postgresqlPassword}@${id}-postgresql:5432/${postgresqlDatabase}`,
                    CLICKHOUSE_DATABASE_URL: `http://${id}-clickhouse:8123/plausible`
                }
            },
            postgresql: {
                volume: `${plausibleDbId}-postgresql-data:/bitnami/postgresql/`,
                image: 'bitnami/postgresql:13.2.0',
                environmentVariables: {
                    POSTGRESQL_PASSWORD: postgresqlPassword,
                    POSTGRESQL_USERNAME: postgresqlUser,
                    POSTGRESQL_DATABASE: postgresqlDatabase
                }
            },
            clickhouse: {
                volume: `${plausibleDbId}-clickhouse-data:/var/lib/clickhouse`,
                image: 'yandex/clickhouse-server:21.3.2.5',
                environmentVariables: {},
                ulimits: {
                    nofile: {
                        soft: 262144,
                        hard: 262144
                    }
                }
            }
        };
        if (serviceSecret.length > 0) {
            serviceSecret.forEach((secret) => {
                config.plausibleAnalytics.environmentVariables[secret.name] = secret.value;
            });
        }
        const network = destinationDockerId && destinationDocker.network;
        const port = getServiceMainPort('plausibleanalytics');

        const { workdir } = await createDirectories({ repository: type, buildId: id });

        const clickhouseConfigXml = `
        <yandex>
          <logger>
              <level>warning</level>
              <console>true</console>
          </logger>
    
          <!-- Stop all the unnecessary logging -->
          <query_thread_log remove="remove"/>
          <query_log remove="remove"/>
          <text_log remove="remove"/>
          <trace_log remove="remove"/>
          <metric_log remove="remove"/>
          <asynchronous_metric_log remove="remove"/>
          <session_log remove="remove"/>
          <part_log remove="remove"/>
      </yandex>`;
        const clickhouseUserConfigXml = `
        <yandex>
          <profiles>
              <default>
                  <log_queries>0</log_queries>
                  <log_query_threads>0</log_query_threads>
              </default>
          </profiles>
      </yandex>`;

        const initQuery = 'CREATE DATABASE IF NOT EXISTS plausible;';
        const initScript = 'clickhouse client --queries-file /docker-entrypoint-initdb.d/init.query';
        await fs.writeFile(`${workdir}/clickhouse-config.xml`, clickhouseConfigXml);
        await fs.writeFile(`${workdir}/clickhouse-user-config.xml`, clickhouseUserConfigXml);
        await fs.writeFile(`${workdir}/init.query`, initQuery);
        await fs.writeFile(`${workdir}/init-db.sh`, initScript);

        const Dockerfile = `
FROM ${config.clickhouse.image}
COPY ./clickhouse-config.xml /etc/clickhouse-server/users.d/logging.xml
COPY ./clickhouse-user-config.xml /etc/clickhouse-server/config.d/logging.xml
COPY ./init.query /docker-entrypoint-initdb.d/init.query
COPY ./init-db.sh /docker-entrypoint-initdb.d/init-db.sh`;

        await fs.writeFile(`${workdir}/Dockerfile`, Dockerfile);

        const { volumes, volumeMounts } = persistentVolumes(id, persistentStorage, config.plausibleAnalytics)

        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.plausibleAnalytics.image,
                    volumes,
                    command:
                        'sh -c "sleep 10 && /entrypoint.sh db createdb && /entrypoint.sh db migrate && /entrypoint.sh db init-admin && /entrypoint.sh run"',
                    environment: config.plausibleAnalytics.environmentVariables,
                    ...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
                    depends_on: [`${id}-postgresql`, `${id}-clickhouse`],
                    labels: makeLabelForServices('plausibleAnalytics'),
                    ...defaultComposeConfiguration(network),
                },
                [`${id}-postgresql`]: {
                    container_name: `${id}-postgresql`,
                    image: config.postgresql.image,
                    environment: config.postgresql.environmentVariables,
                    volumes: [config.postgresql.volume],
                    ...defaultComposeConfiguration(network),
                },
                [`${id}-clickhouse`]: {
                    build: workdir,
                    container_name: `${id}-clickhouse`,
                    environment: config.clickhouse.environmentVariables,
                    volumes: [config.clickhouse.volume],
                    ...defaultComposeConfiguration(network),
                }
            },
            networks: {
                [network]: {
                    external: true
                }
            },
            volumes: {
                ...volumeMounts,
                [config.postgresql.volume.split(':')[0]]: {
                    name: config.postgresql.volume.split(':')[0]
                },
                [config.clickhouse.volume.split(':')[0]]: {
                    name: config.clickhouse.volume.split(':')[0]
                }
            }
        };
        const composeFileDestination = `${workdir}/docker-compose.yaml`;
        await fs.writeFile(composeFileDestination, yaml.dump(composeFile));
        await startServiceContainers(destinationDocker.id, composeFileDestination)
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

async function startNocodbService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const { type, version, destinationDockerId, destinationDocker, serviceSecret, exposePort, persistentStorage } =
            service;
        const network = destinationDockerId && destinationDocker.network;
        const port = getServiceMainPort('nocodb');

        const { workdir } = await createDirectories({ repository: type, buildId: id });
        const image = getServiceImage(type);

        const config = {
            image: `${image}:${version}`,
            volume: `${id}-nc:/usr/app/data`,
            environmentVariables: {}
        };
        if (serviceSecret.length > 0) {
            serviceSecret.forEach((secret) => {
                config.environmentVariables[secret.name] = secret.value;
            });
        }
        const { volumes, volumeMounts } = persistentVolumes(id, persistentStorage, config)
        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.image,
                    volumes,
                    environment: config.environmentVariables,
                    ...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
                    labels: makeLabelForServices('nocodb'),
                    ...defaultComposeConfiguration(network),
                }
            },
            networks: {
                [network]: {
                    external: true
                }
            },
            volumes: volumeMounts
        };
        const composeFileDestination = `${workdir}/docker-compose.yaml`;
        await fs.writeFile(composeFileDestination, yaml.dump(composeFile));
        await startServiceContainers(destinationDocker.id, composeFileDestination)
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

async function startMinioService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const {
            type,
            version,
            fqdn,
            destinationDockerId,
            destinationDocker,
            persistentStorage,
            exposePort,
            minio: { rootUser, rootUserPassword },
            serviceSecret
        } = service;

        const network = destinationDockerId && destinationDocker.network;
        const port = getServiceMainPort('minio');

        const { service: { destinationDocker: { id: dockerId } } } = await prisma.minio.findUnique({ where: { serviceId: id }, include: { service: { include: { destinationDocker: true } } } })
        const publicPort = await getFreePublicPort(id, dockerId);

        const consolePort = 9001;
        const { workdir } = await createDirectories({ repository: type, buildId: id });
        const image = getServiceImage(type);

        const config = {
            image: `${image}:${version}`,
            volume: `${id}-minio-data:/data`,
            environmentVariables: {
                MINIO_ROOT_USER: rootUser,
                MINIO_ROOT_PASSWORD: rootUserPassword,
                MINIO_BROWSER_REDIRECT_URL: fqdn
            }
        };
        if (serviceSecret.length > 0) {
            serviceSecret.forEach((secret) => {
                config.environmentVariables[secret.name] = secret.value;
            });
        }
        const { volumes, volumeMounts } = persistentVolumes(id, persistentStorage, config)
        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.image,
                    command: `server /data --console-address ":${consolePort}"`,
                    environment: config.environmentVariables,
                    volumes,
                    ...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
                    labels: makeLabelForServices('minio'),
                    ...defaultComposeConfiguration(network),
                }
            },
            networks: {
                [network]: {
                    external: true
                }
            },
            volumes: volumeMounts
        };
        const composeFileDestination = `${workdir}/docker-compose.yaml`;
        await fs.writeFile(composeFileDestination, yaml.dump(composeFile));
        await startServiceContainers(destinationDocker.id, composeFileDestination)
        await prisma.minio.update({ where: { serviceId: id }, data: { publicPort } });
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

async function startVscodeService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const {
            type,
            version,
            destinationDockerId,
            destinationDocker,
            serviceSecret,
            persistentStorage,
            exposePort,
            vscodeserver: { password }
        } = service;

        const network = destinationDockerId && destinationDocker.network;
        const port = getServiceMainPort('vscodeserver');

        const { workdir } = await createDirectories({ repository: type, buildId: id });
        const image = getServiceImage(type);

        const config = {
            image: `${image}:${version}`,
            volume: `${id}-vscodeserver-data:/home/coder`,
            environmentVariables: {
                PASSWORD: password
            }
        };
        if (serviceSecret.length > 0) {
            serviceSecret.forEach((secret) => {
                config.environmentVariables[secret.name] = secret.value;
            });
        }
        const { volumes, volumeMounts } = persistentVolumes(id, persistentStorage, config)

        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.image,
                    environment: config.environmentVariables,
                    volumes,
                    ...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
                    labels: makeLabelForServices('vscodeServer'),
                    ...defaultComposeConfiguration(network),
                }
            },
            networks: {
                [network]: {
                    external: true
                }
            },
            volumes: volumeMounts
        };
        const composeFileDestination = `${workdir}/docker-compose.yaml`;
        await fs.writeFile(composeFileDestination, yaml.dump(composeFile));

        await startServiceContainers(destinationDocker.id, composeFileDestination)

        const changePermissionOn = persistentStorage.map((p) => p.path);
        if (changePermissionOn.length > 0) {
            await executeDockerCmd({
                dockerId: destinationDocker.id, command: `docker exec -u root ${id} chown -R 1000:1000 ${changePermissionOn.join(
                    ' '
                )}`
            })
        }
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

async function startWordpressService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const {
            arch,
            type,
            version,
            destinationDockerId,
            serviceSecret,
            destinationDocker,
            persistentStorage,
            exposePort,
            wordpress: {
                mysqlDatabase,
                mysqlHost,
                mysqlPort,
                mysqlUser,
                mysqlPassword,
                extraConfig,
                mysqlRootUser,
                mysqlRootUserPassword,
                ownMysql
            }
        } = service;

        const network = destinationDockerId && destinationDocker.network;
        const image = getServiceImage(type);
        const port = getServiceMainPort('wordpress');

        const { workdir } = await createDirectories({ repository: type, buildId: id });
        const config = {
            wordpress: {
                image: `${image}:${version}`,
                volume: `${id}-wordpress-data:/var/www/html`,
                environmentVariables: {
                    WORDPRESS_DB_HOST: ownMysql ? `${mysqlHost}:${mysqlPort}` : `${id}-mysql`,
                    WORDPRESS_DB_USER: mysqlUser,
                    WORDPRESS_DB_PASSWORD: mysqlPassword,
                    WORDPRESS_DB_NAME: mysqlDatabase,
                    WORDPRESS_CONFIG_EXTRA: extraConfig
                }
            },
            mysql: {
                image: `bitnami/mysql:5.7`,
                volume: `${id}-mysql-data:/bitnami/mysql/data`,
                environmentVariables: {
                    MYSQL_ROOT_PASSWORD: mysqlRootUserPassword,
                    MYSQL_ROOT_USER: mysqlRootUser,
                    MYSQL_USER: mysqlUser,
                    MYSQL_PASSWORD: mysqlPassword,
                    MYSQL_DATABASE: mysqlDatabase
                }
            }
        };
        if (isARM(arch)) {
            config.mysql.image = 'mysql:5.7'
            config.mysql.volume = `${id}-mysql-data:/var/lib/mysql`
        }
        if (serviceSecret.length > 0) {
            serviceSecret.forEach((secret) => {
                config.wordpress.environmentVariables[secret.name] = secret.value;
            });
        }

        const { volumes, volumeMounts } = persistentVolumes(id, persistentStorage, config.wordpress)

        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.wordpress.image,
                    environment: config.wordpress.environmentVariables,
                    volumes,
                    ...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
                    labels: makeLabelForServices('wordpress'),
                    ...defaultComposeConfiguration(network),
                }
            },
            networks: {
                [network]: {
                    external: true
                }
            },
            volumes: volumeMounts
        };
        if (!ownMysql) {
            composeFile.services[id].depends_on = [`${id}-mysql`];
            composeFile.services[`${id}-mysql`] = {
                container_name: `${id}-mysql`,
                image: config.mysql.image,
                volumes: [config.mysql.volume],
                environment: config.mysql.environmentVariables,
                ...defaultComposeConfiguration(network),
            };

            composeFile.volumes[config.mysql.volume.split(':')[0]] = {
                name: config.mysql.volume.split(':')[0]
            };
        }
        const composeFileDestination = `${workdir}/docker-compose.yaml`;
        await fs.writeFile(composeFileDestination, yaml.dump(composeFile));

        await startServiceContainers(destinationDocker.id, composeFileDestination)

        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

async function startVaultwardenService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const { type, version, destinationDockerId, destinationDocker, serviceSecret, exposePort, persistentStorage } =
            service;

        const network = destinationDockerId && destinationDocker.network;
        const port = getServiceMainPort('vaultwarden');

        const { workdir } = await createDirectories({ repository: type, buildId: id });
        const image = getServiceImage(type);

        const config = {
            image: `${image}:${version}`,
            volume: `${id}-vaultwarden-data:/data/`,
            environmentVariables: {}
        };
        if (serviceSecret.length > 0) {
            serviceSecret.forEach((secret) => {
                config.environmentVariables[secret.name] = secret.value;
            });
        }
        const { volumes, volumeMounts } = persistentVolumes(id, persistentStorage, config)
        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.image,
                    environment: config.environmentVariables,
                    volumes,
                    ...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
                    labels: makeLabelForServices('vaultWarden'),
                    ...defaultComposeConfiguration(network),
                }
            },
            networks: {
                [network]: {
                    external: true
                }
            },
            volumes: volumeMounts
        };
        const composeFileDestination = `${workdir}/docker-compose.yaml`;
        await fs.writeFile(composeFileDestination, yaml.dump(composeFile));

        await startServiceContainers(destinationDocker.id, composeFileDestination)

        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

async function startLanguageToolService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const { type, version, destinationDockerId, destinationDocker, serviceSecret, exposePort, persistentStorage } =
            service;
        const network = destinationDockerId && destinationDocker.network;
        const port = getServiceMainPort('languagetool');

        const { workdir } = await createDirectories({ repository: type, buildId: id });
        const image = getServiceImage(type);

        const config = {
            image: `${image}:${version}`,
            volume: `${id}-ngrams:/ngrams`,
            environmentVariables: {}
        };

        if (serviceSecret.length > 0) {
            serviceSecret.forEach((secret) => {
                config.environmentVariables[secret.name] = secret.value;
            });
        }
        const { volumes, volumeMounts } = persistentVolumes(id, persistentStorage, config)
        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.image,
                    environment: config.environmentVariables,
                    ...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
                    volumes,
                    labels: makeLabelForServices('languagetool'),
                    ...defaultComposeConfiguration(network),
                }
            },
            networks: {
                [network]: {
                    external: true
                }
            },
            volumes: volumeMounts
        };
        const composeFileDestination = `${workdir}/docker-compose.yaml`;
        await fs.writeFile(composeFileDestination, yaml.dump(composeFile));

        await startServiceContainers(destinationDocker.id, composeFileDestination)

        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

async function startN8nService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const { type, version, destinationDockerId, destinationDocker, serviceSecret, exposePort, persistentStorage } =
            service;
        const network = destinationDockerId && destinationDocker.network;
        const port = getServiceMainPort('n8n');

        const { workdir } = await createDirectories({ repository: type, buildId: id });
        const image = getServiceImage(type);

        const config = {
            image: `${image}:${version}`,
            volume: `${id}-n8n:/root/.n8n`,
            environmentVariables: {
                WEBHOOK_URL: `${service.fqdn}`
            }
        };
        if (serviceSecret.length > 0) {
            serviceSecret.forEach((secret) => {
                config.environmentVariables[secret.name] = secret.value;
            });
        }
        const { volumes, volumeMounts } = persistentVolumes(id, persistentStorage, config)
        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.image,
                    volumes,
                    environment: config.environmentVariables,
                    labels: makeLabelForServices('n8n'),
                    ...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
                    ...defaultComposeConfiguration(network),
                }
            },
            networks: {
                [network]: {
                    external: true
                }
            },
            volumes: volumeMounts
        };
        const composeFileDestination = `${workdir}/docker-compose.yaml`;
        await fs.writeFile(composeFileDestination, yaml.dump(composeFile));

        await startServiceContainers(destinationDocker.id, composeFileDestination)

        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

async function startUptimekumaService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const { type, version, destinationDockerId, destinationDocker, serviceSecret, exposePort, persistentStorage } =
            service;
        const network = destinationDockerId && destinationDocker.network;
        const port = getServiceMainPort('uptimekuma');

        const { workdir } = await createDirectories({ repository: type, buildId: id });
        const image = getServiceImage(type);

        const config = {
            image: `${image}:${version}`,
            volume: `${id}-uptimekuma:/app/data`,
            environmentVariables: {}
        };
        if (serviceSecret.length > 0) {
            serviceSecret.forEach((secret) => {
                config.environmentVariables[secret.name] = secret.value;
            });
        }
        const { volumes, volumeMounts } = persistentVolumes(id, persistentStorage, config)
        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.image,
                    volumes,
                    environment: config.environmentVariables,
                    ...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
                    labels: makeLabelForServices('uptimekuma'),
                    ...defaultComposeConfiguration(network),
                }
            },
            networks: {
                [network]: {
                    external: true
                }
            },
            volumes: volumeMounts
        };
        const composeFileDestination = `${workdir}/docker-compose.yaml`;
        await fs.writeFile(composeFileDestination, yaml.dump(composeFile));

        await startServiceContainers(destinationDocker.id, composeFileDestination)

        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

async function startGhostService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const {
            type,
            version,
            destinationDockerId,
            destinationDocker,
            serviceSecret,
            persistentStorage,
            exposePort,
            fqdn,
            ghost: {
                defaultEmail,
                defaultPassword,
                mariadbRootUser,
                mariadbRootUserPassword,
                mariadbDatabase,
                mariadbPassword,
                mariadbUser
            }
        } = service;
        const network = destinationDockerId && destinationDocker.network;

        const { workdir } = await createDirectories({ repository: type, buildId: id });
        const image = getServiceImage(type);
        const domain = getDomain(fqdn);
        const port = getServiceMainPort('ghost');
        const isHttps = fqdn.startsWith('https://');
        const config = {
            ghost: {
                image: `${image}:${version}`,
                volume: `${id}-ghost:/bitnami/ghost`,
                environmentVariables: {
                    url: fqdn,
                    GHOST_HOST: domain,
                    GHOST_ENABLE_HTTPS: isHttps ? 'yes' : 'no',
                    GHOST_EMAIL: defaultEmail,
                    GHOST_PASSWORD: defaultPassword,
                    GHOST_DATABASE_HOST: `${id}-mariadb`,
                    GHOST_DATABASE_USER: mariadbUser,
                    GHOST_DATABASE_PASSWORD: mariadbPassword,
                    GHOST_DATABASE_NAME: mariadbDatabase,
                    GHOST_DATABASE_PORT_NUMBER: 3306
                }
            },
            mariadb: {
                image: `bitnami/mariadb:latest`,
                volume: `${id}-mariadb:/bitnami/mariadb`,
                environmentVariables: {
                    MARIADB_USER: mariadbUser,
                    MARIADB_PASSWORD: mariadbPassword,
                    MARIADB_DATABASE: mariadbDatabase,
                    MARIADB_ROOT_USER: mariadbRootUser,
                    MARIADB_ROOT_PASSWORD: mariadbRootUserPassword
                }
            }
        };
        if (serviceSecret.length > 0) {
            serviceSecret.forEach((secret) => {
                config.ghost.environmentVariables[secret.name] = secret.value;
            });
        }

        const { volumes, volumeMounts } = persistentVolumes(id, persistentStorage, config.ghost)
        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.ghost.image,
                    volumes,
                    environment: config.ghost.environmentVariables,
                    ...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
                    labels: makeLabelForServices('ghost'),
                    depends_on: [`${id}-mariadb`],
                    ...defaultComposeConfiguration(network),
                },
                [`${id}-mariadb`]: {
                    container_name: `${id}-mariadb`,
                    image: config.mariadb.image,
                    volumes: [config.mariadb.volume],
                    environment: config.mariadb.environmentVariables,
                    ...defaultComposeConfiguration(network),
                }
            },
            networks: {
                [network]: {
                    external: true
                }
            },
            volumes: {
                ...volumeMounts,
                [config.mariadb.volume.split(':')[0]]: {
                    name: config.mariadb.volume.split(':')[0]
                }
            }
        };
        const composeFileDestination = `${workdir}/docker-compose.yaml`;
        await fs.writeFile(composeFileDestination, yaml.dump(composeFile));

        await startServiceContainers(destinationDocker.id, composeFileDestination)

        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

async function startMeilisearchService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const {
            meiliSearch: { masterKey }
        } = service;
        const { type, version, destinationDockerId, destinationDocker, serviceSecret, exposePort, persistentStorage } =
            service;
        const network = destinationDockerId && destinationDocker.network;
        const port = getServiceMainPort('meilisearch');

        const { workdir } = await createDirectories({ repository: type, buildId: id });
        const image = getServiceImage(type);

        const config = {
            image: `${image}:${version}`,
            volume: `${id}-datams:/data.ms`,
            environmentVariables: {
                MEILI_MASTER_KEY: masterKey
            }
        };

        if (serviceSecret.length > 0) {
            serviceSecret.forEach((secret) => {
                config.environmentVariables[secret.name] = secret.value;
            });
        }
        const { volumes, volumeMounts } = persistentVolumes(id, persistentStorage, config)
        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.image,
                    environment: config.environmentVariables,
                    ...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
                    volumes,
                    labels: makeLabelForServices('meilisearch'),
                    ...defaultComposeConfiguration(network),
                }
            },
            networks: {
                [network]: {
                    external: true
                }
            },
            volumes: volumeMounts
        };
        const composeFileDestination = `${workdir}/docker-compose.yaml`;
        await fs.writeFile(composeFileDestination, yaml.dump(composeFile));
        await startServiceContainers(destinationDocker.id, composeFileDestination)
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

async function startUmamiService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const {
            type,
            version,
            destinationDockerId,
            destinationDocker,
            serviceSecret,
            persistentStorage,
            exposePort,
            umami: {
                umamiAdminPassword,
                postgresqlUser,
                postgresqlPassword,
                postgresqlDatabase,
                hashSalt
            }
        } = service;
        const network = destinationDockerId && destinationDocker.network;
        const port = getServiceMainPort('umami');

        const { workdir } = await createDirectories({ repository: type, buildId: id });
        const image = getServiceImage(type);

        const config = {
            umami: {
                image: `${image}:${version}`,
                environmentVariables: {
                    DATABASE_URL: `postgresql://${postgresqlUser}:${postgresqlPassword}@${id}-postgresql:5432/${postgresqlDatabase}`,
                    DATABASE_TYPE: 'postgresql',
                    HASH_SALT: hashSalt
                }
            },
            postgresql: {
                image: 'postgres:12-alpine',
                volume: `${id}-postgresql-data:/var/lib/postgresql/data`,
                environmentVariables: {
                    POSTGRES_USER: postgresqlUser,
                    POSTGRES_PASSWORD: postgresqlPassword,
                    POSTGRES_DB: postgresqlDatabase
                }
            }
        };
        if (serviceSecret.length > 0) {
            serviceSecret.forEach((secret) => {
                config.umami.environmentVariables[secret.name] = secret.value;
            });
        }

        const initDbSQL = `
		drop table if exists event;
		drop table if exists pageview;
		drop table if exists session;
		drop table if exists website;
		drop table if exists account;
		
		create table account (
			user_id serial primary key,
			username varchar(255) unique not null,
			password varchar(60) not null,
			is_admin bool not null default false,
			created_at timestamp with time zone default current_timestamp,
			updated_at timestamp with time zone default current_timestamp
		);
		
		create table website (
			website_id serial primary key,
			website_uuid uuid unique not null,
			user_id int not null references account(user_id) on delete cascade,
			name varchar(100) not null,
			domain varchar(500),
			share_id varchar(64) unique,
			created_at timestamp with time zone default current_timestamp
		);
		
		create table session (
			session_id serial primary key,
			session_uuid uuid unique not null,
			website_id int not null references website(website_id) on delete cascade,
			created_at timestamp with time zone default current_timestamp,
			hostname varchar(100),
			browser varchar(20),
			os varchar(20),
			device varchar(20),
			screen varchar(11),
			language varchar(35),
			country char(2)
		);
		
		create table pageview (
			view_id serial primary key,
			website_id int not null references website(website_id) on delete cascade,
			session_id int not null references session(session_id) on delete cascade,
			created_at timestamp with time zone default current_timestamp,
			url varchar(500) not null,
			referrer varchar(500)
		);
		
		create table event (
			event_id serial primary key,
			website_id int not null references website(website_id) on delete cascade,
			session_id int not null references session(session_id) on delete cascade,
			created_at timestamp with time zone default current_timestamp,
			url varchar(500) not null,
			event_type varchar(50) not null,
			event_value varchar(50) not null
		);
		
		create index website_user_id_idx on website(user_id);
		
		create index session_created_at_idx on session(created_at);
		create index session_website_id_idx on session(website_id);
		
		create index pageview_created_at_idx on pageview(created_at);
		create index pageview_website_id_idx on pageview(website_id);
		create index pageview_session_id_idx on pageview(session_id);
		create index pageview_website_id_created_at_idx on pageview(website_id, created_at);
		create index pageview_website_id_session_id_created_at_idx on pageview(website_id, session_id, created_at);
		
		create index event_created_at_idx on event(created_at);
		create index event_website_id_idx on event(website_id);
		create index event_session_id_idx on event(session_id);
		
		insert into account (username, password, is_admin) values ('admin', '${bcrypt.hashSync(
            umamiAdminPassword,
            10
        )}', true);`;
        await fs.writeFile(`${workdir}/schema.postgresql.sql`, initDbSQL);
        const Dockerfile = `
	  FROM ${config.postgresql.image}
	  COPY ./schema.postgresql.sql /docker-entrypoint-initdb.d/schema.postgresql.sql`;
        await fs.writeFile(`${workdir}/Dockerfile`, Dockerfile);
        const { volumes, volumeMounts } = persistentVolumes(id, persistentStorage, config.umami)
        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.umami.image,
                    environment: config.umami.environmentVariables,
                    volumes,
                    ...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
                    labels: makeLabelForServices('umami'),
                    depends_on: [`${id}-postgresql`],
                    ...defaultComposeConfiguration(network),
                },
                [`${id}-postgresql`]: {
                    build: workdir,
                    container_name: `${id}-postgresql`,
                    environment: config.postgresql.environmentVariables,
                    volumes: [config.postgresql.volume],
                    ...defaultComposeConfiguration(network),
                }
            },
            networks: {
                [network]: {
                    external: true
                }
            },
            volumes: {
                ...volumeMounts,
                [config.postgresql.volume.split(':')[0]]: {
                    name: config.postgresql.volume.split(':')[0]
                }
            }
        };
        const composeFileDestination = `${workdir}/docker-compose.yaml`;
        await fs.writeFile(composeFileDestination, yaml.dump(composeFile));
        await startServiceContainers(destinationDocker.id, composeFileDestination)
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

async function startHasuraService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const {
            type,
            version,
            destinationDockerId,
            destinationDocker,
            persistentStorage,
            serviceSecret,
            exposePort,
            hasura: { postgresqlUser, postgresqlPassword, postgresqlDatabase }
        } = service;
        const network = destinationDockerId && destinationDocker.network;
        const port = getServiceMainPort('hasura');

        const { workdir } = await createDirectories({ repository: type, buildId: id });
        const image = getServiceImage(type);

        const config = {
            hasura: {
                image: `${image}:${version}`,
                environmentVariables: {
                    HASURA_GRAPHQL_METADATA_DATABASE_URL: `postgresql://${postgresqlUser}:${postgresqlPassword}@${id}-postgresql:5432/${postgresqlDatabase}`
                }
            },
            postgresql: {
                image: 'postgres:12-alpine',
                volume: `${id}-postgresql-data:/var/lib/postgresql/data`,
                environmentVariables: {
                    POSTGRES_USER: postgresqlUser,
                    POSTGRES_PASSWORD: postgresqlPassword,
                    POSTGRES_DB: postgresqlDatabase
                }
            }
        };
        if (serviceSecret.length > 0) {
            serviceSecret.forEach((secret) => {
                config.hasura.environmentVariables[secret.name] = secret.value;
            });
        }

        const { volumes, volumeMounts } = persistentVolumes(id, persistentStorage, config.hasura)
        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.hasura.image,
                    environment: config.hasura.environmentVariables,
                    volumes,
                    labels: makeLabelForServices('hasura'),
                    ...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
                    depends_on: [`${id}-postgresql`],
                    ...defaultComposeConfiguration(network),
                },
                [`${id}-postgresql`]: {
                    image: config.postgresql.image,
                    container_name: `${id}-postgresql`,
                    environment: config.postgresql.environmentVariables,
                    volumes: [config.postgresql.volume],
                    ...defaultComposeConfiguration(network),
                }
            },
            networks: {
                [network]: {
                    external: true
                }
            },
            volumes: {
                ...volumeMounts,
                [config.postgresql.volume.split(':')[0]]: {
                    name: config.postgresql.volume.split(':')[0]
                }
            }
        };
        const composeFileDestination = `${workdir}/docker-compose.yaml`;
        await fs.writeFile(composeFileDestination, yaml.dump(composeFile));

        await startServiceContainers(destinationDocker.id, composeFileDestination)

        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

async function startFiderService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const {
            type,
            version,
            fqdn,
            destinationDockerId,
            destinationDocker,
            serviceSecret,
            persistentStorage,
            exposePort,
            fider: {
                postgresqlUser,
                postgresqlPassword,
                postgresqlDatabase,
                jwtSecret,
                emailNoreply,
                emailMailgunApiKey,
                emailMailgunDomain,
                emailMailgunRegion,
                emailSmtpHost,
                emailSmtpPort,
                emailSmtpUser,
                emailSmtpPassword,
                emailSmtpEnableStartTls
            }
        } = service;
        const network = destinationDockerId && destinationDocker.network;
        const port = getServiceMainPort('fider');

        const { workdir } = await createDirectories({ repository: type, buildId: id });
        const image = getServiceImage(type);
        const config = {
            fider: {
                image: `${image}:${version}`,
                environmentVariables: {
                    BASE_URL: fqdn,
                    DATABASE_URL: `postgresql://${postgresqlUser}:${postgresqlPassword}@${id}-postgresql:5432/${postgresqlDatabase}?sslmode=disable`,
                    JWT_SECRET: `${jwtSecret.replace(/\$/g, '$$$')}`,
                    EMAIL_NOREPLY: emailNoreply,
                    EMAIL_MAILGUN_API: emailMailgunApiKey,
                    EMAIL_MAILGUN_REGION: emailMailgunRegion,
                    EMAIL_MAILGUN_DOMAIN: emailMailgunDomain,
                    EMAIL_SMTP_HOST: emailSmtpHost,
                    EMAIL_SMTP_PORT: emailSmtpPort,
                    EMAIL_SMTP_USER: emailSmtpUser,
                    EMAIL_SMTP_PASSWORD: emailSmtpPassword,
                    EMAIL_SMTP_ENABLE_STARTTLS: emailSmtpEnableStartTls
                }
            },
            postgresql: {
                image: 'postgres:12-alpine',
                volume: `${id}-postgresql-data:/var/lib/postgresql/data`,
                environmentVariables: {
                    POSTGRES_USER: postgresqlUser,
                    POSTGRES_PASSWORD: postgresqlPassword,
                    POSTGRES_DB: postgresqlDatabase
                }
            }
        };
        if (serviceSecret.length > 0) {
            serviceSecret.forEach((secret) => {
                config.fider.environmentVariables[secret.name] = secret.value;
            });
        }
        const { volumes, volumeMounts } = persistentVolumes(id, persistentStorage, config.fider)
        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.fider.image,
                    environment: config.fider.environmentVariables,
                    volumes,
                    labels: makeLabelForServices('fider'),
                    ...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
                    depends_on: [`${id}-postgresql`],
                    ...defaultComposeConfiguration(network),
                },
                [`${id}-postgresql`]: {
                    image: config.postgresql.image,
                    container_name: `${id}-postgresql`,
                    environment: config.postgresql.environmentVariables,
                    volumes: [config.postgresql.volume],
                    ...defaultComposeConfiguration(network),
                }
            },
            networks: {
                [network]: {
                    external: true
                }
            },
            volumes: {
                ...volumeMounts,
                [config.postgresql.volume.split(':')[0]]: {
                    name: config.postgresql.volume.split(':')[0]
                }
            }
        };
        const composeFileDestination = `${workdir}/docker-compose.yaml`;
        await fs.writeFile(composeFileDestination, yaml.dump(composeFile));

        await startServiceContainers(destinationDocker.id, composeFileDestination)

        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

async function startAppWriteService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const { version, fqdn, destinationDocker, secrets, exposePort, network, port, workdir, image, appwrite } = await defaultServiceConfigurations({ id, teamId })

        let isStatsEnabled = false
        if (secrets.find(s => s === '_APP_USAGE_STATS=enabled')) {
            isStatsEnabled = true
        }
        const {
            opensslKeyV1,
            executorSecret,
            mariadbHost,
            mariadbPort,
            mariadbUser,
            mariadbPassword,
            mariadbRootUser,
            mariadbRootUserPassword,
            mariadbDatabase
        } = appwrite;

        const dockerCompose = {
            [id]: {
                image: `${image}:${version}`,
                container_name: id,
                labels: makeLabelForServices('appwrite'),
                ...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
                "volumes": [
                    `${id}-uploads:/storage/uploads:rw`,
                    `${id}-cache:/storage/cache:rw`,
                    `${id}-config:/storage/config:rw`,
                    `${id}-certificates:/storage/certificates:rw`,
                    `${id}-functions:/storage/functions:rw`
                ],
                "depends_on": [
                    `${id}-mariadb`,
                    `${id}-redis`,
                ],
                "environment": [
                    "_APP_ENV=production",
                    "_APP_LOCALE=en",
                    `_APP_OPENSSL_KEY_V1=${opensslKeyV1}`,
                    `_APP_DOMAIN=${fqdn}`,
                    `_APP_DOMAIN_TARGET=${fqdn}`,
                    `_APP_REDIS_HOST=${id}-redis`,
                    "_APP_REDIS_PORT=6379",
                    `_APP_DB_HOST=${mariadbHost}`,
                    `_APP_DB_PORT=${mariadbPort}`,
                    `_APP_DB_SCHEMA=${mariadbDatabase}`,
                    `_APP_DB_USER=${mariadbUser}`,
                    `_APP_DB_PASS=${mariadbPassword}`,
                    `_APP_INFLUXDB_HOST=${id}-influxdb`,
                    "_APP_INFLUXDB_PORT=8086",
                    `_APP_EXECUTOR_SECRET=${executorSecret}`,
                    `_APP_EXECUTOR_HOST=http://${id}-executor/v1`,
                    `_APP_STATSD_HOST=${id}-telegraf`,
                    "_APP_STATSD_PORT=8125",
                    ...secrets
                ],
                ...defaultComposeConfiguration(network),
            },
            [`${id}-realtime`]: {
                image: `${image}:${version}`,
                container_name: `${id}-realtime`,
                entrypoint: "realtime",
                labels: makeLabelForServices('appwrite'),
                "depends_on": [
                    `${id}-mariadb`,
                    `${id}-redis`,
                ],
                "environment": [
                    "_APP_ENV=production",
                    `_APP_OPENSSL_KEY_V1=${opensslKeyV1}`,
                    `_APP_REDIS_HOST=${id}-redis`,
                    "_APP_REDIS_PORT=6379",
                    `_APP_DB_HOST=${mariadbHost}`,
                    `_APP_DB_PORT=${mariadbPort}`,
                    `_APP_DB_SCHEMA=${mariadbDatabase}`,
                    `_APP_DB_USER=${mariadbUser}`,
                    `_APP_DB_PASS=${mariadbPassword}`,
                    ...secrets
                ],
                ...defaultComposeConfiguration(network),
            },
            [`${id}-worker-audits`]: {

                image: `${image}:${version}`,
                container_name: `${id}-worker-audits`,
                labels: makeLabelForServices('appwrite'),
                "entrypoint": "worker-audits",
                "depends_on": [
                    `${id}-mariadb`,
                    `${id}-redis`,
                ],
                "environment": [
                    "_APP_ENV=production",
                    `_APP_OPENSSL_KEY_V1=${opensslKeyV1}`,
                    `_APP_REDIS_HOST=${id}-redis`,
                    "_APP_REDIS_PORT=6379",
                    `_APP_DB_HOST=${mariadbHost}`,
                    `_APP_DB_PORT=${mariadbPort}`,
                    `_APP_DB_SCHEMA=${mariadbDatabase}`,
                    `_APP_DB_USER=${mariadbUser}`,
                    `_APP_DB_PASS=${mariadbPassword}`,
                    ...secrets
                ],
                ...defaultComposeConfiguration(network),
            },
            [`${id}-worker-webhooks`]: {
                image: `${image}:${version}`,
                container_name: `${id}-worker-webhooks`,
                labels: makeLabelForServices('appwrite'),
                "entrypoint": "worker-webhooks",
                "depends_on": [
                    `${id}-mariadb`,
                    `${id}-redis`,
                ],
                "environment": [
                    "_APP_ENV=production",
                    `_APP_OPENSSL_KEY_V1=${opensslKeyV1}`,
                    `_APP_REDIS_HOST=${id}-redis`,
                    "_APP_REDIS_PORT=6379",
                    ...secrets
                ],
                ...defaultComposeConfiguration(network),
            },
            [`${id}-worker-deletes`]: {
                image: `${image}:${version}`,
                container_name: `${id}-worker-deletes`,
                labels: makeLabelForServices('appwrite'),
                "entrypoint": "worker-deletes",
                "depends_on": [
                    `${id}-mariadb`,
                    `${id}-redis`,
                ],
                "volumes": [
                    `${id}-uploads:/storage/uploads:rw`,
                    `${id}-cache:/storage/cache:rw`,
                    `${id}-config:/storage/config:rw`,
                    `${id}-certificates:/storage/certificates:rw`,
                    `${id}-functions:/storage/functions:rw`,
                    `${id}-builds:/storage/builds:rw`,
                ],
                "environment": [
                    "_APP_ENV=production",
                    `_APP_OPENSSL_KEY_V1=${opensslKeyV1}`,
                    `_APP_REDIS_HOST=${id}-redis`,
                    "_APP_REDIS_PORT=6379",
                    `_APP_DB_HOST=${mariadbHost}`,
                    `_APP_DB_PORT=${mariadbPort}`,
                    `_APP_DB_SCHEMA=${mariadbDatabase}`,
                    `_APP_DB_USER=${mariadbUser}`,
                    `_APP_DB_PASS=${mariadbPassword}`,
                    `_APP_EXECUTOR_SECRET=${executorSecret}`,
                    `_APP_EXECUTOR_HOST=http://${id}-executor/v1`,
                    ...secrets
                ],
                ...defaultComposeConfiguration(network),
            },
            [`${id}-worker-databases`]: {
                image: `${image}:${version}`,
                container_name: `${id}-worker-databases`,
                labels: makeLabelForServices('appwrite'),
                "entrypoint": "worker-databases",
                "depends_on": [
                    `${id}-mariadb`,
                    `${id}-redis`,
                ],
                "environment": [
                    "_APP_ENV=production",
                    `_APP_OPENSSL_KEY_V1=${opensslKeyV1}`,
                    `_APP_REDIS_HOST=${id}-redis`,
                    "_APP_REDIS_PORT=6379",
                    `_APP_DB_HOST=${mariadbHost}`,
                    `_APP_DB_PORT=${mariadbPort}`,
                    `_APP_DB_SCHEMA=${mariadbDatabase}`,
                    `_APP_DB_USER=${mariadbUser}`,
                    `_APP_DB_PASS=${mariadbPassword}`,
                    ...secrets
                ],
                ...defaultComposeConfiguration(network),
            },
            [`${id}-worker-builds`]: {
                image: `${image}:${version}`,
                container_name: `${id}-worker-builds`,
                labels: makeLabelForServices('appwrite'),
                "entrypoint": "worker-builds",
                "depends_on": [
                    `${id}-mariadb`,
                    `${id}-redis`,
                ],
                "environment": [
                    "_APP_ENV=production",
                    `_APP_OPENSSL_KEY_V1=${opensslKeyV1}`,
                    `_APP_EXECUTOR_SECRET=${executorSecret}`,
                    `_APP_EXECUTOR_HOST=http://${id}-executor/v1`,
                    `_APP_REDIS_HOST=${id}-redis`,
                    "_APP_REDIS_PORT=6379",
                    `_APP_DB_HOST=${mariadbHost}`,
                    `_APP_DB_PORT=${mariadbPort}`,
                    `_APP_DB_SCHEMA=${mariadbDatabase}`,
                    `_APP_DB_USER=${mariadbUser}`,
                    `_APP_DB_PASS=${mariadbPassword}`,
                    ...secrets
                ],
                ...defaultComposeConfiguration(network),
            },
            [`${id}-worker-certificates`]: {
                image: `${image}:${version}`,
                container_name: `${id}-worker-certificates`,
                labels: makeLabelForServices('appwrite'),
                "entrypoint": "worker-certificates",
                "depends_on": [
                    `${id}-mariadb`,
                    `${id}-redis`,
                ],
                "volumes": [
                    `${id}-config:/storage/config:rw`,
                    `${id}-certificates:/storage/certificates:rw`,
                ],
                "environment": [
                    "_APP_ENV=production",
                    `_APP_OPENSSL_KEY_V1=${opensslKeyV1}`,
                    `_APP_DOMAIN=${fqdn}`,
                    `_APP_DOMAIN_TARGET=${fqdn}`,
                    `_APP_REDIS_HOST=${id}-redis`,
                    "_APP_REDIS_PORT=6379",
                    `_APP_DB_HOST=${mariadbHost}`,
                    `_APP_DB_PORT=${mariadbPort}`,
                    `_APP_DB_SCHEMA=${mariadbDatabase}`,
                    `_APP_DB_USER=${mariadbUser}`,
                    `_APP_DB_PASS=${mariadbPassword}`,
                    ...secrets
                ],
                ...defaultComposeConfiguration(network),
            },
            [`${id}-worker-functions`]: {
                image: `${image}:${version}`,
                container_name: `${id}-worker-functions`,
                labels: makeLabelForServices('appwrite'),
                "entrypoint": "worker-functions",
                "depends_on": [
                    `${id}-mariadb`,
                    `${id}-redis`,
                    `${id}-executor`
                ],
                "environment": [
                    "_APP_ENV=production",
                    `_APP_OPENSSL_KEY_V1=${opensslKeyV1}`,
                    `_APP_REDIS_HOST=${id}-redis`,
                    "_APP_REDIS_PORT=6379",
                    `_APP_DB_HOST=${mariadbHost}`,
                    `_APP_DB_PORT=${mariadbPort}`,
                    `_APP_DB_SCHEMA=${mariadbDatabase}`,
                    `_APP_DB_USER=${mariadbUser}`,
                    `_APP_DB_PASS=${mariadbPassword}`,
                    `_APP_EXECUTOR_SECRET=${executorSecret}`,
                    `_APP_EXECUTOR_HOST=http://${id}-executor/v1`,
                    ...secrets
                ],
                ...defaultComposeConfiguration(network),
            },
            [`${id}-executor`]: {
                image: `${image}:${version}`,
                container_name: `${id}-executor`,
                labels: makeLabelForServices('appwrite'),
                "entrypoint": "executor",
                "stop_signal": "SIGINT",
                "volumes": [
                    `${id}-functions:/storage/functions:rw`,
                    `${id}-builds:/storage/builds:rw`,
                    "/var/run/docker.sock:/var/run/docker.sock",
                    "/tmp:/tmp:rw"
                ],
                "depends_on": [
                    `${id}-mariadb`,
                    `${id}-redis`,
                    `${id}`
                ],
                "environment": [
                    "_APP_ENV=production",
                    `_APP_EXECUTOR_SECRET=${executorSecret}`,
                    ...secrets
                ],
                ...defaultComposeConfiguration(network),
            },
            [`${id}-worker-mails`]: {
                image: `${image}:${version}`,
                container_name: `${id}-worker-mails`,
                labels: makeLabelForServices('appwrite'),
                "entrypoint": "worker-mails",
                "depends_on": [
                    `${id}-redis`,
                ],
                "environment": [
                    "_APP_ENV=production",
                    `_APP_OPENSSL_KEY_V1=${opensslKeyV1}`,
                    `_APP_REDIS_HOST=${id}-redis`,
                    "_APP_REDIS_PORT=6379",
                    ...secrets
                ],
                ...defaultComposeConfiguration(network),
            },
            [`${id}-worker-messaging`]: {
                image: `${image}:${version}`,
                container_name: `${id}-worker-messaging`,
                labels: makeLabelForServices('appwrite'),
                "entrypoint": "worker-messaging",
                "depends_on": [
                    `${id}-redis`,
                ],
                "environment": [
                    "_APP_ENV=production",
                    `_APP_REDIS_HOST=${id}-redis`,
                    "_APP_REDIS_PORT=6379",
                    ...secrets
                ],
                ...defaultComposeConfiguration(network),
            },
            [`${id}-maintenance`]: {
                image: `${image}:${version}`,
                container_name: `${id}-maintenance`,
                labels: makeLabelForServices('appwrite'),
                "entrypoint": "maintenance",
                "depends_on": [
                    `${id}-redis`,
                ],
                "environment": [
                    "_APP_ENV=production",
                    `_APP_OPENSSL_KEY_V1=${opensslKeyV1}`,
                    `_APP_DOMAIN=${fqdn}`,
                    `_APP_DOMAIN_TARGET=${fqdn}`,
                    `_APP_REDIS_HOST=${id}-redis`,
                    "_APP_REDIS_PORT=6379",
                    `_APP_DB_HOST=${mariadbHost}`,
                    `_APP_DB_PORT=${mariadbPort}`,
                    `_APP_DB_SCHEMA=${mariadbDatabase}`,
                    `_APP_DB_USER=${mariadbUser}`,
                    `_APP_DB_PASS=${mariadbPassword}`,
                    ...secrets
                ],
                ...defaultComposeConfiguration(network),
            },
            [`${id}-schedule`]: {
                image: `${image}:${version}`,
                container_name: `${id}-schedule`,
                labels: makeLabelForServices('appwrite'),
                "entrypoint": "schedule",
                "depends_on": [
                    `${id}-redis`,
                ],
                "environment": [
                    "_APP_ENV=production",
                    `_APP_REDIS_HOST=${id}-redis`,
                    "_APP_REDIS_PORT=6379",
                    ...secrets
                ],
                ...defaultComposeConfiguration(network),
            },
            [`${id}-mariadb`]: {
                "image": "mariadb:10.7",
                container_name: `${id}-mariadb`,
                labels: makeLabelForServices('appwrite'),
                "volumes": [
                    `${id}-mariadb:/var/lib/mysql:rw`
                ],
                "environment": [
                    `MYSQL_ROOT_USER=${mariadbRootUser}`,
                    `MYSQL_ROOT_PASSWORD=${mariadbRootUserPassword}`,
                    `MYSQL_USER=${mariadbUser}`,
                    `MYSQL_PASSWORD=${mariadbPassword}`,
                    `MYSQL_DATABASE=${mariadbDatabase}`
                ],
                "command": "mysqld --innodb-flush-method=fsync",
                ...defaultComposeConfiguration(network),
            },
            [`${id}-redis`]: {
                "image": "redis:6.2-alpine",
                container_name: `${id}-redis`,
                "command": `redis-server --maxmemory 512mb --maxmemory-policy allkeys-lru --maxmemory-samples 5\n`,
                "volumes": [
                    `${id}-redis:/data:rw`
                ],
                ...defaultComposeConfiguration(network),
            },

        };
        if (isStatsEnabled) {
            dockerCompose[id].depends_on.push(`${id}-influxdb`);
            dockerCompose[`${id}-usage`] = {
                image: `${image}:${version}`,
                container_name: `${id}-usage`,
                labels: makeLabelForServices('appwrite'),
                "entrypoint": "usage",
                "depends_on": [
                    `${id}-mariadb`,
                    `${id}-influxdb`,
                ],
                "environment": [
                    "_APP_ENV=production",
                    `_APP_OPENSSL_KEY_V1=${opensslKeyV1}`,
                    `_APP_DB_HOST=${mariadbHost}`,
                    `_APP_DB_PORT=${mariadbPort}`,
                    `_APP_DB_SCHEMA=${mariadbDatabase}`,
                    `_APP_DB_USER=${mariadbUser}`,
                    `_APP_DB_PASS=${mariadbPassword}`,
                    `_APP_INFLUXDB_HOST=${id}-influxdb`,
                    "_APP_INFLUXDB_PORT=8086",
                    `_APP_REDIS_HOST=${id}-redis`,
                    "_APP_REDIS_PORT=6379",
                    ...secrets
                ],
                ...defaultComposeConfiguration(network),
            }
            dockerCompose[`${id}-influxdb`] = {
                "image": "appwrite/influxdb:1.5.0",
                container_name: `${id}-influxdb`,
                "volumes": [
                    `${id}-influxdb:/var/lib/influxdb:rw`
                ],
                ...defaultComposeConfiguration(network),
            }
            dockerCompose[`${id}-telegraf`] = {
                "image": "appwrite/telegraf:1.4.0",
                container_name: `${id}-telegraf`,
                "environment": [
                    `_APP_INFLUXDB_HOST=${id}-influxdb`,
                    "_APP_INFLUXDB_PORT=8086",
                ],
                ...defaultComposeConfiguration(network),
            }
        }

        const composeFile: any = {
            version: '3.8',
            services: dockerCompose,
            networks: {
                [network]: {
                    external: true
                }
            },
            volumes: {
                [`${id}-uploads`]: {
                    name: `${id}-uploads`
                },
                [`${id}-cache`]: {
                    name: `${id}-cache`
                },
                [`${id}-config`]: {
                    name: `${id}-config`
                },
                [`${id}-certificates`]: {
                    name: `${id}-certificates`
                },
                [`${id}-functions`]: {
                    name: `${id}-functions`
                },
                [`${id}-builds`]: {
                    name: `${id}-builds`
                },
                [`${id}-mariadb`]: {
                    name: `${id}-mariadb`
                },
                [`${id}-redis`]: {
                    name: `${id}-redis`
                },
                [`${id}-influxdb`]: {
                    name: `${id}-influxdb`
                }
            }

        };
        const composeFileDestination = `${workdir}/docker-compose.yaml`;
        await fs.writeFile(composeFileDestination, yaml.dump(composeFile));

        await startServiceContainers(destinationDocker.id, composeFileDestination)

        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
async function startServiceContainers(dockerId, composeFileDestination) {
    await executeDockerCmd({ dockerId, command: `docker compose -f ${composeFileDestination} pull` })
    await executeDockerCmd({ dockerId, command: `docker compose -f ${composeFileDestination} build --no-cache` })
    await executeDockerCmd({ dockerId, command: `docker compose -f ${composeFileDestination} create` })
    await executeDockerCmd({ dockerId, command: `docker compose -f ${composeFileDestination} start` })
    await asyncSleep(1000);
    await executeDockerCmd({ dockerId, command: `docker compose -f ${composeFileDestination} up -d` })
}
async function stopServiceContainers(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const { destinationDockerId } = await getServiceFromDB({ id, teamId });
        if (destinationDockerId) {
            await executeDockerCmd({
                dockerId: destinationDockerId,
                command: `docker ps -a --filter 'label=com.docker.compose.project=${id}' --format {{.ID}}|xargs -n 1 docker stop -t 0`
            })
            await executeDockerCmd({
                dockerId: destinationDockerId,
                command: `docker ps -a --filter 'label=com.docker.compose.project=${id}' --format {{.ID}}|xargs -n 1 docker rm --force`
            })
            return {}
        }
        throw { status: 500, message: 'Could not stop containers.' }
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
async function startMoodleService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const {
            type,
            version,
            fqdn,
            destinationDockerId,
            destinationDocker,
            serviceSecret,
            persistentStorage,
            exposePort,
            moodle: {
                defaultUsername,
                defaultPassword,
                defaultEmail,
                mariadbRootUser,
                mariadbRootUserPassword,
                mariadbDatabase,
                mariadbPassword,
                mariadbUser
            }
        } = service;
        const network = destinationDockerId && destinationDocker.network;
        const port = getServiceMainPort('moodle');

        const { workdir } = await createDirectories({ repository: type, buildId: id });
        const image = getServiceImage(type);
        const config = {
            moodle: {
                image: `${image}:${version}`,
                volume: `${id}-data:/bitnami/moodle`,
                environmentVariables: {
                    MOODLE_USERNAME: defaultUsername,
                    MOODLE_PASSWORD: defaultPassword,
                    MOODLE_EMAIL: defaultEmail,
                    MOODLE_DATABASE_HOST: `${id}-mariadb`,
                    MOODLE_DATABASE_USER: mariadbUser,
                    MOODLE_DATABASE_PASSWORD: mariadbPassword,
                    MOODLE_DATABASE_NAME: mariadbDatabase,
                    MOODLE_REVERSEPROXY: 'yes'
                }
            },
            mariadb: {
                image: 'bitnami/mariadb:latest',
                volume: `${id}-mariadb-data:/bitnami/mariadb`,
                environmentVariables: {
                    MARIADB_USER: mariadbUser,
                    MARIADB_PASSWORD: mariadbPassword,
                    MARIADB_DATABASE: mariadbDatabase,
                    MARIADB_ROOT_USER: mariadbRootUser,
                    MARIADB_ROOT_PASSWORD: mariadbRootUserPassword
                }
            }
        };
        if (serviceSecret.length > 0) {
            serviceSecret.forEach((secret) => {
                config.moodle.environmentVariables[secret.name] = secret.value;
            });
        }
        const { volumes, volumeMounts } = persistentVolumes(id, persistentStorage, config.moodle)
        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.moodle.image,
                    environment: config.moodle.environmentVariables,
                    networks: [network],
                    volumes,
                    restart: 'always',
                    labels: makeLabelForServices('moodle'),
                    ...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
                    deploy: {
                        restart_policy: {
                            condition: 'on-failure',
                            delay: '5s',
                            max_attempts: 3,
                            window: '120s'
                        }
                    },
                    depends_on: [`${id}-mariadb`]
                },
                [`${id}-mariadb`]: {
                    container_name: `${id}-mariadb`,
                    image: config.mariadb.image,
                    environment: config.mariadb.environmentVariables,
                    networks: [network],
                    volumes: [],
                    restart: 'always',
                    deploy: {
                        restart_policy: {
                            condition: 'on-failure',
                            delay: '5s',
                            max_attempts: 3,
                            window: '120s'
                        }
                    },
                    depends_on: []
                }

            },
            networks: {
                [network]: {
                    external: true
                }
            },
            volumes: {
                ...volumeMounts,
                [config.mariadb.volume.split(':')[0]]: {
                    name: config.mariadb.volume.split(':')[0]
                }
            }

        };
        const composeFileDestination = `${workdir}/docker-compose.yaml`;
        await fs.writeFile(composeFileDestination, yaml.dump(composeFile));

        await startServiceContainers(destinationDocker.id, composeFileDestination)

        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

async function startGlitchTipService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const {
            type,
            version,
            fqdn,
            destinationDockerId,
            destinationDocker,
            serviceSecret,
            persistentStorage,
            exposePort,
            glitchTip: {
                postgresqlDatabase,
                postgresqlPassword,
                postgresqlUser,
                secretKeyBase,
                defaultEmail,
                defaultUsername,
                defaultPassword,
                defaultFromEmail,
                emailSmtpHost,
                emailSmtpPort,
                emailSmtpUser,
                emailSmtpPassword,
                emailSmtpUseTls,
                emailSmtpUseSsl,
                emailBackend,
                mailgunApiKey,
                sendgridApiKey,
                enableOpenUserRegistration,
            }
        } = service;
        const network = destinationDockerId && destinationDocker.network;
        const port = getServiceMainPort('glitchTip');

        const { workdir } = await createDirectories({ repository: type, buildId: id });
        const image = getServiceImage(type);

        const config = {
            glitchTip: {
                image: `${image}:${version}`,
                environmentVariables: {
                    PORT: port,
                    GLITCHTIP_DOMAIN: fqdn,
                    SECRET_KEY: secretKeyBase,
                    DATABASE_URL: `postgresql://${postgresqlUser}:${postgresqlPassword}@${id}-postgresql:5432/${postgresqlDatabase}`,
                    REDIS_URL: `redis://${id}-redis:6379/0`,
                    DEFAULT_FROM_EMAIL: defaultFromEmail,
                    EMAIL_HOST: emailSmtpHost,
                    EMAIL_PORT: emailSmtpPort,
                    EMAIL_HOST_USER: emailSmtpUser,
                    EMAIL_HOST_PASSWORD: emailSmtpPassword,
                    EMAIL_USE_TLS: emailSmtpUseTls,
                    EMAIL_USE_SSL: emailSmtpUseSsl,
                    EMAIL_BACKEND: emailBackend,
                    MAILGUN_API_KEY: mailgunApiKey,
                    SENDGRID_API_KEY: sendgridApiKey,
                    ENABLE_OPEN_USER_REGISTRATION: enableOpenUserRegistration,
                    DJANGO_SUPERUSER_EMAIL: defaultEmail,
                    DJANGO_SUPERUSER_USERNAME: defaultUsername,
                    DJANGO_SUPERUSER_PASSWORD: defaultPassword,
                }
            },
            postgresql: {
                image: 'postgres:14-alpine',
                volume: `${id}-postgresql-data:/var/lib/postgresql/data`,
                environmentVariables: {
                    POSTGRES_USER: postgresqlUser,
                    POSTGRES_PASSWORD: postgresqlPassword,
                    POSTGRES_DB: postgresqlDatabase
                }
            },
            redis: {
                image: 'redis:7-alpine',
                volume: `${id}-redis-data:/data`,
            }
        };
        if (serviceSecret.length > 0) {
            serviceSecret.forEach((secret) => {
                config.glitchTip.environmentVariables[secret.name] = secret.value;
            });
        }
        const { volumes, volumeMounts } = persistentVolumes(id, persistentStorage, config.glitchTip)
        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.glitchTip.image,
                    environment: config.glitchTip.environmentVariables,
                    volumes,
                    labels: makeLabelForServices('glitchTip'),
                    ...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
                    depends_on: [`${id}-postgresql`, `${id}-redis`],
                    ...defaultComposeConfiguration(network),
                },
                [`${id}-worker`]: {
                    container_name: `${id}-worker`,
                    image: config.glitchTip.image,
                    command: './bin/run-celery-with-beat.sh',
                    environment: config.glitchTip.environmentVariables,
                    depends_on: [`${id}-postgresql`, `${id}-redis`],
                    ...defaultComposeConfiguration(network),
                },
                [`${id}-setup`]: {
                    container_name: `${id}-setup`,
                    image: config.glitchTip.image,
                    command: 'sh -c "(./manage.py migrate || true) && (./manage.py createsuperuser --noinput || true)"',
                    environment: config.glitchTip.environmentVariables,
                    networks: [network],
                    restart: "no",
                    depends_on: [`${id}-postgresql`, `${id}-redis`]
                },
                [`${id}-postgresql`]: {
                    image: config.postgresql.image,
                    container_name: `${id}-postgresql`,
                    environment: config.postgresql.environmentVariables,
                    volumes: [config.postgresql.volume],
                    ...defaultComposeConfiguration(network),
                },
                [`${id}-redis`]: {
                    image: config.redis.image,
                    container_name: `${id}-redis`,
                    volumes: [config.redis.volume],
                    ...defaultComposeConfiguration(network),
                }
            },
            networks: {
                [network]: {
                    external: true
                }
            },
            volumes: {
                ...volumeMounts,
                [config.postgresql.volume.split(':')[0]]: {
                    name: config.postgresql.volume.split(':')[0]
                },
                [config.redis.volume.split(':')[0]]: {
                    name: config.redis.volume.split(':')[0]
                }
            }
        };
        const composeFileDestination = `${workdir}/docker-compose.yaml`;
        await fs.writeFile(composeFileDestination, yaml.dump(composeFile));

        await executeDockerCmd({ dockerId: destinationDocker.id, command: `docker compose -f ${composeFileDestination} pull` })
        await executeDockerCmd({ dockerId: destinationDocker.id, command: `docker compose -f ${composeFileDestination} up --build -d` })

        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

async function startSearXNGService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const { type, version, destinationDockerId, destinationDocker, serviceSecret, exposePort, persistentStorage, fqdn, searxng: { secretKey, redisPassword } } =
            service;
        const network = destinationDockerId && destinationDocker.network;
        const port = getServiceMainPort('searxng');

        const { workdir } = await createDirectories({ repository: type, buildId: id });
        const image = getServiceImage(type);

        const config = {
            searxng: {
                image: `${image}:${version}`,
                volume: `${id}-searxng:/etc/searxng`,
                environmentVariables: {
                    SEARXNG_BASE_URL: `${fqdn}`
                },
            },
            redis: {
                image: 'redis:7-alpine',
            }
        };

        const settingsYml = `
        # see https://docs.searxng.org/admin/engines/settings.html#use-default-settings
        use_default_settings: true
        server:
          secret_key: ${secretKey}
          limiter: true
          image_proxy: true
        ui:
          static_use_hash: true
        redis:
          url: redis://:${redisPassword}@${id}-redis:6379/0`

        const Dockerfile = `
        FROM ${config.searxng.image}
        COPY ./settings.yml /etc/searxng/settings.yml`;

        if (serviceSecret.length > 0) {
            serviceSecret.forEach((secret) => {
                config.searxng.environmentVariables[secret.name] = secret.value;
            });
        }
        const { volumes, volumeMounts } = persistentVolumes(id, persistentStorage, config)
        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    build: workdir,
                    container_name: id,
                    volumes,
                    environment: config.searxng.environmentVariables,
                    ...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
                    labels: makeLabelForServices('searxng'),
                    cap_drop: ['ALL'],
                    cap_add: ['CHOWN', 'SETGID', 'SETUID', 'DAC_OVERRIDE'],
                    depends_on: [`${id}-redis`],
                    ...defaultComposeConfiguration(network),
                },
                [`${id}-redis`]: {
                    container_name: `${id}-redis`,
                    image: config.redis.image,
                    command: `redis-server --requirepass ${redisPassword} --save "" --appendonly "no"`,
                    labels: makeLabelForServices('searxng'),
                    cap_drop: ['ALL'],
                    cap_add: ['SETGID', 'SETUID', 'DAC_OVERRIDE'],
                    ...defaultComposeConfiguration(network),
                },
            },
            networks: {
                [network]: {
                    external: true
                }
            },
            volumes: volumeMounts
        };
        const composeFileDestination = `${workdir}/docker-compose.yaml`;
        await fs.writeFile(composeFileDestination, yaml.dump(composeFile));
        await fs.writeFile(`${workdir}/Dockerfile`, Dockerfile);
        await fs.writeFile(`${workdir}/settings.yml`, settingsYml);

        await startServiceContainers(destinationDocker.id, composeFileDestination)
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
