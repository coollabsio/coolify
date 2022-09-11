import type { FastifyReply, FastifyRequest } from 'fastify';
import fs from 'fs/promises';
import yaml from 'js-yaml';
import bcrypt from 'bcryptjs';
import { ServiceStartStop } from '../../routes/api/v1/services/types';
import { asyncSleep, ComposeFile, createDirectories, defaultComposeConfiguration, errorHandler, executeDockerCmd, getDomain, getFreePublicPort, getServiceFromDB, getServiceImage, getServiceMainPort, isARM, isDev, makeLabelForServices, persistentVolumes, prisma } from '../common';
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
        if (type === 'weblate') {
            return await startWeblateService(request)
        }
        if (type === 'taiga') {
            return await startTaigaService(request)
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
                volumes: [`${plausibleDbId}-postgresql-data:/bitnami/postgresql/`],
                image: 'bitnami/postgresql:13.2.0',
                environmentVariables: {
                    POSTGRESQL_PASSWORD: postgresqlPassword,
                    POSTGRESQL_USERNAME: postgresqlUser,
                    POSTGRESQL_DATABASE: postgresqlDatabase
                }
            },
            clickhouse: {
                volumes: [`${plausibleDbId}-clickhouse-data:/var/lib/clickhouse`],
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

        const { volumeMounts } = persistentVolumes(id, persistentStorage, config)

        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.plausibleAnalytics.image,
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
                    volumes: config.postgresql.volumes,
                    ...defaultComposeConfiguration(network),
                },
                [`${id}-clickhouse`]: {
                    build: workdir,
                    container_name: `${id}-clickhouse`,
                    environment: config.clickhouse.environmentVariables,
                    volumes: config.clickhouse.volumes,
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
            nocodb: {
                image: `${image}:${version}`,
                volumes: [`${id}-nc:/usr/app/data`],
                environmentVariables: {}
            }

        };
        if (serviceSecret.length > 0) {
            serviceSecret.forEach((secret) => {
                config.nocodb.environmentVariables[secret.name] = secret.value;
            });
        }
        const { volumeMounts } = persistentVolumes(id, persistentStorage, config)
        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.nocodb.image,
                    volumes: config.nocodb.volumes,
                    environment: config.nocodb.environmentVariables,
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
            minio: { rootUser, rootUserPassword, apiFqdn },
            serviceSecret
        } = service;

        const network = destinationDockerId && destinationDocker.network;
        const port = getServiceMainPort('minio');

        const { service: { destinationDocker: { remoteEngine, engine, remoteIpAddress } } } = await prisma.minio.findUnique({ where: { serviceId: id }, include: { service: { include: { destinationDocker: true } } } })
        const publicPort = await getFreePublicPort({ id, remoteEngine, engine, remoteIpAddress });

        const consolePort = 9001;
        const { workdir } = await createDirectories({ repository: type, buildId: id });
        const image = getServiceImage(type);

        const config = {
            minio: {
                image: `${image}:${version}`,
                volumes: [`${id}-minio-data:/data`],
                environmentVariables: {
                    MINIO_SERVER_URL: apiFqdn,
                    MINIO_DOMAIN: getDomain(fqdn),
                    MINIO_ROOT_USER: rootUser,
                    MINIO_ROOT_PASSWORD: rootUserPassword,
                    MINIO_BROWSER_REDIRECT_URL: fqdn
                }
            }

        };
        if (serviceSecret.length > 0) {
            serviceSecret.forEach((secret) => {
                config.minio.environmentVariables[secret.name] = secret.value;
            });
        }
        const { volumeMounts } = persistentVolumes(id, persistentStorage, config)
        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.minio.image,
                    command: `server /data --console-address ":${consolePort}"`,
                    environment: config.minio.environmentVariables,
                    volumes: config.minio.volumes,
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
            vscodeserver: {
                image: `${image}:${version}`,
                volumes: [`${id}-vscodeserver-data:/home/coder`],
                environmentVariables: {
                    PASSWORD: password
                }
            }

        };
        if (serviceSecret.length > 0) {
            serviceSecret.forEach((secret) => {
                config.vscodeserver.environmentVariables[secret.name] = secret.value;
            });
        }
        const { volumeMounts } = persistentVolumes(id, persistentStorage, config)

        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.vscodeserver.image,
                    environment: config.vscodeserver.environmentVariables,
                    volumes: config.vscodeserver.volumes,
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
                volumes: [`${id}-wordpress-data:/var/www/html`],
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
                volumes: [`${id}-mysql-data:/bitnami/mysql/data`],
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
            config.mysql.volumes = [`${id}-mysql-data:/var/lib/mysql`]
        }
        if (serviceSecret.length > 0) {
            serviceSecret.forEach((secret) => {
                config.wordpress.environmentVariables[secret.name] = secret.value;
            });
        }

        const { volumeMounts } = persistentVolumes(id, persistentStorage, config)

        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.wordpress.image,
                    environment: config.wordpress.environmentVariables,
                    volumes: config.wordpress.volumes,
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
                volumes: config.mysql.volumes,
                environment: config.mysql.environmentVariables,
                ...defaultComposeConfiguration(network),
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
            vaultwarden: {
                image: `${image}:${version}`,
                volumes: [`${id}-vaultwarden-data:/data/`],
                environmentVariables: {}
            }

        };
        if (serviceSecret.length > 0) {
            serviceSecret.forEach((secret) => {
                config.vaultwarden.environmentVariables[secret.name] = secret.value;
            });
        }
        const { volumeMounts } = persistentVolumes(id, persistentStorage, config)
        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.vaultwarden.image,
                    environment: config.vaultwarden.environmentVariables,
                    volumes: config.vaultwarden.volumes,
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
            languagetool: {
                image: `${image}:${version}`,
                volumes: [`${id}-ngrams:/ngrams`],
                environmentVariables: {}
            }
        };

        if (serviceSecret.length > 0) {
            serviceSecret.forEach((secret) => {
                config.languagetool.environmentVariables[secret.name] = secret.value;
            });
        }
        const { volumeMounts } = persistentVolumes(id, persistentStorage, config)
        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.languagetool.image,
                    environment: config.languagetool.environmentVariables,
                    ...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
                    volumes: config.languagetool.volumes,
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
            n8n: {
                image: `${image}:${version}`,
                volumes: [`${id}-n8n:/root/.n8n`],
                environmentVariables: {
                    WEBHOOK_URL: `${service.fqdn}`
                }
            }
        };
        if (serviceSecret.length > 0) {
            serviceSecret.forEach((secret) => {
                config.n8n.environmentVariables[secret.name] = secret.value;
            });
        }
        const { volumeMounts } = persistentVolumes(id, persistentStorage, config)
        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.n8n.image,
                    volumes: config.n8n.volumes,
                    environment: config.n8n.environmentVariables,
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
            uptimekuma: {
                image: `${image}:${version}`,
                volumes: [`${id}-uptimekuma:/app/data`],
                environmentVariables: {}
            }
        };
        if (serviceSecret.length > 0) {
            serviceSecret.forEach((secret) => {
                config.uptimekuma.environmentVariables[secret.name] = secret.value;
            });
        }
        const { volumeMounts } = persistentVolumes(id, persistentStorage, config)
        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.uptimekuma.image,
                    volumes: config.uptimekuma.volumes,
                    environment: config.uptimekuma.environmentVariables,
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
                volumes: [`${id}-ghost:/bitnami/ghost`],
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
                volumes: [`${id}-mariadb:/bitnami/mariadb`],
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

        const { volumeMounts } = persistentVolumes(id, persistentStorage, config)
        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.ghost.image,
                    volumes: config.ghost.volumes,
                    environment: config.ghost.environmentVariables,
                    ...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
                    labels: makeLabelForServices('ghost'),
                    depends_on: [`${id}-mariadb`],
                    ...defaultComposeConfiguration(network),
                },
                [`${id}-mariadb`]: {
                    container_name: `${id}-mariadb`,
                    image: config.mariadb.image,
                    volumes: config.mariadb.volumes,
                    environment: config.mariadb.environmentVariables,
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
            meilisearch: {
                image: `${image}:${version}`,
                volumes: [`${id}-datams:/data.ms`],
                environmentVariables: {
                    MEILI_MASTER_KEY: masterKey
                }
            }
        };

        if (serviceSecret.length > 0) {
            serviceSecret.forEach((secret) => {
                config.meilisearch.environmentVariables[secret.name] = secret.value;
            });
        }
        const { volumeMounts } = persistentVolumes(id, persistentStorage, config)
        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.meilisearch.image,
                    environment: config.meilisearch.environmentVariables,
                    ...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
                    volumes: config.meilisearch.volumes,
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
                volumes: [`${id}-postgresql-data:/var/lib/postgresql/data`],
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
        const { volumeMounts } = persistentVolumes(id, persistentStorage, config)
        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.umami.image,
                    environment: config.umami.environmentVariables,
                    ...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
                    labels: makeLabelForServices('umami'),
                    depends_on: [`${id}-postgresql`],
                    ...defaultComposeConfiguration(network),
                },
                [`${id}-postgresql`]: {
                    build: workdir,
                    container_name: `${id}-postgresql`,
                    environment: config.postgresql.environmentVariables,
                    volumes: config.postgresql.volumes,
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
                volumes: [`${id}-postgresql-data:/var/lib/postgresql/data`],
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

        const { volumeMounts } = persistentVolumes(id, persistentStorage, config)
        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.hasura.image,
                    environment: config.hasura.environmentVariables,
                    labels: makeLabelForServices('hasura'),
                    ...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
                    depends_on: [`${id}-postgresql`],
                    ...defaultComposeConfiguration(network),
                },
                [`${id}-postgresql`]: {
                    image: config.postgresql.image,
                    container_name: `${id}-postgresql`,
                    environment: config.postgresql.environmentVariables,
                    volumes: config.postgresql.volumes,
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
                volumes: [`${id}-postgresql-data:/var/lib/postgresql/data`],
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
        const { volumeMounts } = persistentVolumes(id, persistentStorage, config)
        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.fider.image,
                    environment: config.fider.environmentVariables,
                    labels: makeLabelForServices('fider'),
                    ...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
                    depends_on: [`${id}-postgresql`],
                    ...defaultComposeConfiguration(network),
                },
                [`${id}-postgresql`]: {
                    image: config.postgresql.image,
                    container_name: `${id}-postgresql`,
                    environment: config.postgresql.environmentVariables,
                    volumes: config.postgresql.volumes,
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
                volumes: [
                    `${id}-uploads:/storage/uploads:rw`,
                    `${id}-cache:/storage/cache:rw`,
                    `${id}-config:/storage/config:rw`,
                    `${id}-certificates:/storage/certificates:rw`,
                    `${id}-functions:/storage/functions:rw`
                ],
                depends_on: [
                    `${id}-mariadb`,
                    `${id}-redis`,
                ],
                environment: [
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
                depends_on: [
                    `${id}-mariadb`,
                    `${id}-redis`,
                ],
                environment: [
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
                entrypoint: "worker-audits",
                depends_on: [
                    `${id}-mariadb`,
                    `${id}-redis`,
                ],
                environment: [
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
                entrypoint: "worker-webhooks",
                depends_on: [
                    `${id}-mariadb`,
                    `${id}-redis`,
                ],
                environment: [
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
                entrypoint: "worker-deletes",
                depends_on: [
                    `${id}-mariadb`,
                    `${id}-redis`,
                ],
                volumes: [
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
                entrypoint: "worker-databases",
                depends_on: [
                    `${id}-mariadb`,
                    `${id}-redis`,
                ],
                environment: [
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
                entrypoint: "worker-builds",
                depends_on: [
                    `${id}-mariadb`,
                    `${id}-redis`,
                ],
                environment: [
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
                entrypoint: "worker-certificates",
                depends_on: [
                    `${id}-mariadb`,
                    `${id}-redis`,
                ],
                volumes: [
                    `${id}-config:/storage/config:rw`,
                    `${id}-certificates:/storage/certificates:rw`,
                ],
                environment: [
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
                entrypoint: "worker-functions",
                depends_on: [
                    `${id}-mariadb`,
                    `${id}-redis`,
                    `${id}-executor`
                ],
                environment: [
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
                entrypoint: "executor",
                stop_signal: "SIGINT",
                volumes: [
                    `${id}-functions:/storage/functions:rw`,
                    `${id}-builds:/storage/builds:rw`,
                    "/var/run/docker.sock:/var/run/docker.sock",
                    "/tmp:/tmp:rw"
                ],
                depends_on: [
                    `${id}-mariadb`,
                    `${id}-redis`,
                    `${id}`
                ],
                environment: [
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
                entrypoint: "worker-mails",
                depends_on: [
                    `${id}-redis`,
                ],
                environment: [
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
                entrypoint: "worker-messaging",
                depends_on: [
                    `${id}-redis`,
                ],
                environment: [
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
                entrypoint: "maintenance",
                depends_on: [
                    `${id}-redis`,
                ],
                environment: [
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
                entrypoint: "schedule",
                depends_on: [
                    `${id}-redis`,
                ],
                environment: [
                    "_APP_ENV=production",
                    `_APP_REDIS_HOST=${id}-redis`,
                    "_APP_REDIS_PORT=6379",
                    ...secrets
                ],
                ...defaultComposeConfiguration(network),
            },
            [`${id}-mariadb`]: {
                image: "mariadb:10.7",
                container_name: `${id}-mariadb`,
                labels: makeLabelForServices('appwrite'),
                volumes: [
                    `${id}-mariadb:/var/lib/mysql:rw`
                ],
                environment: [
                    `MYSQL_ROOT_USER=${mariadbRootUser}`,
                    `MYSQL_ROOT_PASSWORD=${mariadbRootUserPassword}`,
                    `MYSQL_USER=${mariadbUser}`,
                    `MYSQL_PASSWORD=${mariadbPassword}`,
                    `MYSQL_DATABASE=${mariadbDatabase}`
                ],
                command: "mysqld --innodb-flush-method=fsync",
                ...defaultComposeConfiguration(network),
            },
            [`${id}-redis`]: {
                image: "redis:6.2-alpine",
                container_name: `${id}-redis`,
                command: `redis-server --maxmemory 512mb --maxmemory-policy allkeys-lru --maxmemory-samples 5\n`,
                volumes: [
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
                entrypoint: "usage",
                depends_on: [
                    `${id}-mariadb`,
                    `${id}-influxdb`,
                ],
                environment: [
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
                image: "appwrite/influxdb:1.5.0",
                container_name: `${id}-influxdb`,
                volumes: [
                    `${id}-influxdb:/var/lib/influxdb:rw`
                ],
                ...defaultComposeConfiguration(network),
            }
            dockerCompose[`${id}-telegraf`] = {
                image: "appwrite/telegraf:1.4.0",
                container_name: `${id}-telegraf`,
                environment: [
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
                volumes: [`${id}-data:/bitnami/moodle`],
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
                volumes: [`${id}-mariadb-data:/bitnami/mariadb`],
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
        const { volumeMounts } = persistentVolumes(id, persistentStorage, config)
        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.moodle.image,
                    environment: config.moodle.environmentVariables,
                    volumes: config.moodle.volumes,
                    labels: makeLabelForServices('moodle'),
                    ...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
                    depends_on: [`${id}-mariadb`],
                    ...defaultComposeConfiguration(network),
                },
                [`${id}-mariadb`]: {
                    container_name: `${id}-mariadb`,
                    image: config.mariadb.image,
                    environment: config.mariadb.environmentVariables,
                    volumes: config.mariadb.volumes,
                    ...defaultComposeConfiguration(network),
                    depends_on: []
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
                    EMAIL_USE_TLS: emailSmtpUseTls ? 'True' : 'False',
                    EMAIL_USE_SSL: emailSmtpUseSsl ? 'True' : 'False',
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
                volumes: [`${id}-postgresql-data:/var/lib/postgresql/data`],
                environmentVariables: {
                    POSTGRES_USER: postgresqlUser,
                    POSTGRES_PASSWORD: postgresqlPassword,
                    POSTGRES_DB: postgresqlDatabase
                }
            },
            redis: {
                image: 'redis:7-alpine',
                volumes: [`${id}-redis-data:/data`],
            }
        };
        if (serviceSecret.length > 0) {
            serviceSecret.forEach((secret) => {
                config.glitchTip.environmentVariables[secret.name] = secret.value;
            });
        }
        const { volumeMounts } = persistentVolumes(id, persistentStorage, config)
        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.glitchTip.image,
                    environment: config.glitchTip.environmentVariables,
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
                    volumes: config.postgresql.volumes,
                    ...defaultComposeConfiguration(network),
                },
                [`${id}-redis`]: {
                    image: config.redis.image,
                    container_name: `${id}-redis`,
                    volumes: config.redis.volumes,
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
                volumes: [`${id}-searxng:/etc/searxng`],
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
        const { volumeMounts } = persistentVolumes(id, persistentStorage, config)
        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    build: workdir,
                    container_name: id,
                    volumes: config.searxng.volumes,
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


async function startWeblateService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const {
            weblate: { adminPassword, postgresqlHost, postgresqlPort, postgresqlUser, postgresqlPassword, postgresqlDatabase }
        } = service;
        const { type, version, destinationDockerId, destinationDocker, serviceSecret, exposePort, persistentStorage, fqdn } =
            service;
        const network = destinationDockerId && destinationDocker.network;
        const port = getServiceMainPort('weblate');

        const { workdir } = await createDirectories({ repository: type, buildId: id });
        const image = getServiceImage(type);

        const config = {
            weblate: {
                image: `${image}:${version}`,
                volumes: [`${id}-data:/app/data`],
                environmentVariables: {
                    WEBLATE_SITE_DOMAIN: getDomain(fqdn),
                    WEBLATE_ADMIN_PASSWORD: adminPassword,
                    POSTGRES_PASSWORD: postgresqlPassword,
                    POSTGRES_USER: postgresqlUser,
                    POSTGRES_DATABASE: postgresqlDatabase,
                    POSTGRES_HOST: postgresqlHost,
                    POSTGRES_PORT: postgresqlPort,
                    REDIS_HOST: `${id}-redis`,
                }
            },
            postgresql: {
                image: `postgres:14-alpine`,
                volumes: [`${id}-postgresql-data:/var/lib/postgresql/data`],
                environmentVariables: {
                    POSTGRES_PASSWORD: postgresqlPassword,
                    POSTGRES_USER: postgresqlUser,
                    POSTGRES_DB: postgresqlDatabase,
                    POSTGRES_HOST: postgresqlHost,
                    POSTGRES_PORT: postgresqlPort,
                }
            },
            redis: {
                image: `redis:6-alpine`,
                volumes: [`${id}-redis-data:/data`],
            }

        };

        if (serviceSecret.length > 0) {
            serviceSecret.forEach((secret) => {
                config.weblate.environmentVariables[secret.name] = secret.value;
            });
        }
        const { volumeMounts } = persistentVolumes(id, persistentStorage, config)
        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.weblate.image,
                    environment: config.weblate.environmentVariables,
                    ...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
                    volumes: config.weblate.volumes,
                    labels: makeLabelForServices('weblate'),
                    ...defaultComposeConfiguration(network),
                },
                [`${id}-postgresql`]: {
                    container_name: `${id}-postgresql`,
                    image: config.postgresql.image,
                    environment: config.postgresql.environmentVariables,
                    ...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
                    volumes: config.postgresql.volumes,
                    labels: makeLabelForServices('weblate'),
                    ...defaultComposeConfiguration(network),
                },
                [`${id}-redis`]: {
                    container_name: `${id}-redis`,
                    image: config.redis.image,
                    ...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
                    volumes: config.redis.volumes,
                    labels: makeLabelForServices('weblate'),
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

async function startTaigaService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const {
            taiga: { secretKey, djangoAdminUser, djangoAdminPassword, erlangSecret, rabbitMQUser, rabbitMQPassword, postgresqlHost, postgresqlPort, postgresqlUser, postgresqlPassword, postgresqlDatabase }
        } = service;
        const { type, version, destinationDockerId, destinationDocker, serviceSecret, exposePort, persistentStorage, fqdn } =
            service;
        const network = destinationDockerId && destinationDocker.network;
        const port = getServiceMainPort('taiga');

        const { workdir } = await createDirectories({ repository: type, buildId: id });
        const image = getServiceImage(type);

        const isHttps = fqdn.startsWith('https://');
        const superUserEntrypoint = `#!/bin/sh
        set -e
        python manage.py makemigrations
        python manage.py migrate

        if [ "$DJANGO_SUPERUSER_USERNAME" ]
        then
            python manage.py createsuperuser \
                --noinput \
                --username $DJANGO_SUPERUSER_USERNAME \
                --email $DJANGO_SUPERUSER_EMAIL
        fi
        exec "$@"`;
        const entrypoint = `#!/bin/sh
        set -e

        /taiga-back/docker/entrypoint_superuser.sh || echo "Superuser creation failed, but continue"
        /taiga-back/docker/entrypoint.sh
        
        exec "$@"`;

        const DockerfileBack = `
        FROM taigaio/taiga-back:latest
        COPY ./entrypoint_superuser.sh /taiga-back/docker/entrypoint_superuser.sh
        COPY ./entrypoint_coolify.sh /taiga-back/docker/entrypoint_coolify.sh
        RUN ["chmod", "+x", "/taiga-back/docker/entrypoint_superuser.sh"]
        RUN ["chmod", "+x", "/taiga-back/docker/entrypoint_coolify.sh"]
        RUN ["chmod", "+x", "/taiga-back/docker/entrypoint.sh"]`;

        const DockerfileGateway = `
        FROM nginx:1.19-alpine
        COPY ./nginx.conf /etc/nginx/conf.d/default.conf`;

        const nginxConf = `server {
            listen 80 default_server;
        
            client_max_body_size 100M;
            charset utf-8;
        
            # Frontend
            location / {
                proxy_pass http://${id}-taiga-front/;
                proxy_pass_header Server;
                proxy_set_header Host $http_host;
                proxy_redirect off;
                proxy_set_header X-Real-IP $remote_addr;
                proxy_set_header X-Scheme $scheme;
            }
        
            # API
            location /api/ {
                proxy_pass http://${id}-taiga-back:8000/api/;
                proxy_pass_header Server;
                proxy_set_header Host $http_host;
                proxy_redirect off;
                proxy_set_header X-Real-IP $remote_addr;
                proxy_set_header X-Scheme $scheme;
            }
        
            # Admin
            location /admin/ {
                proxy_pass http://${id}-taiga-back:8000/admin/;
                proxy_pass_header Server;
                proxy_set_header Host $http_host;
                proxy_redirect off;
                proxy_set_header X-Real-IP $remote_addr;
                proxy_set_header X-Scheme $scheme;
            }
        
            # Static
            location /static/ {
                alias /taiga/static/;
            }
        
            # Media
            location /_protected/ {
                internal;
                alias /taiga/media/;
                add_header Content-disposition "attachment";
            }
        
            # Unprotected section
            location /media/exports/ {
                alias /taiga/media/exports/;
                add_header Content-disposition "attachment";
            }
        
            location /media/ {
                proxy_set_header Host $http_host;
                proxy_set_header X-Real-IP $remote_addr;
                proxy_set_header X-Scheme $scheme;
                proxy_set_header X-Forwarded-Proto $scheme;
                proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
                proxy_pass http://${id}-taiga-protected:8003/;
                proxy_redirect off;
            }
        
            # Events
            location /events {
                proxy_pass http://${id}-taiga-events:8888/events;
                proxy_http_version 1.1;
                proxy_set_header Upgrade $http_upgrade;
                proxy_set_header Connection "upgrade";
                proxy_connect_timeout 7d;
                proxy_send_timeout 7d;
                proxy_read_timeout 7d;
            }
        }`
        await fs.writeFile(`${workdir}/entrypoint_superuser.sh`, superUserEntrypoint);
        await fs.writeFile(`${workdir}/entrypoint_coolify.sh`, entrypoint);
        await fs.writeFile(`${workdir}/DockerfileBack`, DockerfileBack);
        await fs.writeFile(`${workdir}/DockerfileGateway`, DockerfileGateway);
        await fs.writeFile(`${workdir}/nginx.conf`, nginxConf);

        const config = {
            ['taiga-gateway']: {
                volumes: [`${id}-static-data:/taiga-back/static`, `${id}-media-data:/taiga-back/media`],
            },
            ['taiga-front']: {
                image: `${image}:${version}`,
                environmentVariables: {
                    TAIGA_URL: fqdn,
                    TAIGA_WEBSOCKETS_URL: isHttps ? `wss://${getDomain(fqdn)}` : `ws://${getDomain(fqdn)}`,
                    TAIGA_SUBPATH: "",
                    PUBLIC_REGISTER_ENABLED: isDev ? "true" : "false",
                }
            },
            ['taiga-back']: {
                volumes: [`${id}-static-data:/taiga-back/static`, `${id}-media-data:/taiga-back/media`],
                environmentVariables: {
                    POSTGRES_DB: postgresqlDatabase,
                    POSTGRES_HOST: postgresqlHost,
                    POSTGRES_PORT: postgresqlPort,
                    POSTGRES_USER: postgresqlUser,
                    POSTGRES_PASSWORD: postgresqlPassword,
                    TAIGA_SECRET_KEY: secretKey,
                    TAIGA_SITES_SCHEME: isHttps ? 'https' : 'http',
                    TAIGA_SITES_DOMAIN: getDomain(fqdn),
                    TAIGA_SUBPATH: "",
                    EVENTS_PUSH_BACKEND_URL: `amqp://${rabbitMQUser}:${rabbitMQPassword}@${id}-taiga-rabbitmq:5672/taiga`,
                    CELERY_BROKER_URL: `amqp://${rabbitMQUser}:${rabbitMQPassword}@${id}-taiga-rabbitmq:5672/taiga`,
                    RABBITMQ_USER: rabbitMQUser,
                    RABBITMQ_PASS: rabbitMQPassword,
                    ENABLE_TELEMETRY: "False",
                    DJANGO_SUPERUSER_EMAIL: `admin@${getDomain(fqdn)}`,
                    DJANGO_SUPERUSER_PASSWORD: djangoAdminPassword,
                    DJANGO_SUPERUSER_USERNAME: djangoAdminUser,
                    PUBLIC_REGISTER_ENABLED: isDev ? "True" : "False",
                    SESSION_COOKIE_SECURE: isDev ? "False" : "True",
                    CSRF_COOKIE_SECURE: isDev ? "False" : "True",

                }
            },
            ['taiga-async']: {
                image: `taigaio/taiga-back:latest`,
                volumes: [`${id}-static-data:/taiga-back/static`, `${id}-media-data:/taiga-back/media`],
                environmentVariables: {
                    POSTGRES_DB: postgresqlDatabase,
                    POSTGRES_HOST: postgresqlHost,
                    POSTGRES_PORT: postgresqlPort,
                    POSTGRES_USER: postgresqlUser,
                    POSTGRES_PASSWORD: postgresqlPassword,
                    TAIGA_SECRET_KEY: secretKey,
                    TAIGA_SITES_SCHEME: isHttps ? 'https' : 'http',
                    TAIGA_SITES_DOMAIN: getDomain(fqdn),
                    TAIGA_SUBPATH: "",
                    RABBITMQ_USER: rabbitMQUser,
                    RABBITMQ_PASS: rabbitMQPassword,
                    ENABLE_TELEMETRY: "False",
                }
            },
            ['taiga-rabbitmq']: {
                image: `rabbitmq:3.8-management-alpine`,
                volumes: [`${id}-events:/var/lib/rabbitmq`],
                environmentVariables: {
                    RABBITMQ_ERLANG_COOKIE: erlangSecret,
                    RABBITMQ_DEFAULT_USER: rabbitMQUser,
                    RABBITMQ_DEFAULT_PASS: rabbitMQPassword,
                    RABBITMQ_DEFAULT_VHOST: 'taiga'
                }
            },
            ['taiga-protected']: {
                image: `taigaio/taiga-protected:latest`,
                environmentVariables: {
                    MAX_AGE: 360,
                    SECRET_KEY: secretKey,
                    TAIGA_URL: fqdn
                }
            },
            ['taiga-events']: {
                image: `taigaio/taiga-events:latest`,
                environmentVariables: {
                    RABBITMQ_URL: `amqp://${rabbitMQUser}:${rabbitMQPassword}@${id}-taiga-rabbitmq:5672/taiga`,
                    RABBITMQ_USER: rabbitMQUser,
                    RABBITMQ_PASS: rabbitMQPassword,
                    TAIGA_SECRET_KEY: secretKey,
                }
            },

            postgresql: {
                image: `postgres:12.3`,
                volumes: [`${id}-postgresql-data:/var/lib/postgresql/data`],
                environmentVariables: {
                    POSTGRES_PASSWORD: postgresqlPassword,
                    POSTGRES_USER: postgresqlUser,
                    POSTGRES_DB: postgresqlDatabase
                }
            }
        };

        if (serviceSecret.length > 0) {
            serviceSecret.forEach((secret) => {
                config['taiga-back'].environmentVariables[secret.name] = secret.value;
            });
        }
        const { volumeMounts } = persistentVolumes(id, persistentStorage, config)

        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    build: {
                        context: '.',
                        dockerfile: 'DockerfileGateway',
                    },
                    container_name: id,
                    volumes: config['taiga-gateway'].volumes,
                    labels: makeLabelForServices('taiga'),
                    ...defaultComposeConfiguration(network),
                },
                [`${id}-taiga-front`]: {
                    container_name: `${id}-taiga-front`,
                    image: config['taiga-front'].image,
                    environment: config['taiga-front'].environmentVariables,
                    labels: makeLabelForServices('taiga'),
                    ...defaultComposeConfiguration(network),
                },
                [`${id}-taiga-back`]: {
                    build: {
                        context: '.',
                        dockerfile: 'DockerfileBack',
                    },
                    entrypoint: '/taiga-back/docker/entrypoint_coolify.sh',
                    container_name: `${id}-taiga-back`,
                    environment: config['taiga-back'].environmentVariables,
                    ...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
                    volumes: config['taiga-back'].volumes,
                    labels: makeLabelForServices('taiga'),
                    ...defaultComposeConfiguration(network),
                },

                [`${id}-async`]: {
                    container_name: `${id}-taiga-async`,
                    image: config['taiga-async'].image,
                    entrypoint: ["/taiga-back/docker/async_entrypoint.sh"],
                    environment: config['taiga-async'].environmentVariables,
                    volumes: config['taiga-async'].volumes,
                    labels: makeLabelForServices('taiga'),
                    ...defaultComposeConfiguration(network),
                },
                [`${id}-taiga-rabbitmq`]: {
                    container_name: `${id}-taiga-rabbitmq`,
                    image: config['taiga-rabbitmq'].image,
                    volumes: config['taiga-rabbitmq'].volumes,
                    environment: config['taiga-rabbitmq'].environmentVariables,
                    labels: makeLabelForServices('taiga'),
                    ...defaultComposeConfiguration(network),
                },
                [`${id}-taiga-protected`]: {
                    container_name: `${id}-taiga-protected`,
                    image: config['taiga-protected'].image,
                    environment: config['taiga-protected'].environmentVariables,
                    labels: makeLabelForServices('taiga'),
                    ...defaultComposeConfiguration(network),
                },
                [`${id}-taiga-events`]: {
                    container_name: `${id}-taiga-events`,
                    image: config['taiga-events'].image,
                    environment: config['taiga-events'].environmentVariables,
                    labels: makeLabelForServices('taiga'),
                    ...defaultComposeConfiguration(network),
                },
                [`${id}-postgresql`]: {
                    container_name: `${id}-postgresql`,
                    image: config.postgresql.image,
                    environment: config.postgresql.environmentVariables,
                    ...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
                    volumes: config.postgresql.volumes,
                    labels: makeLabelForServices('taiga'),
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

        await startServiceContainers(destinationDocker.id, composeFileDestination)
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

