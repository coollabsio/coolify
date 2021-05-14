import type { Request } from '@sveltejs/kit';
import generator from 'generate-password';
import { promises as fs } from 'fs';
import yaml from 'js-yaml';
import { docker } from '$lib/api/docker';
import { baseServiceConfiguration } from '$lib/api/applications/common';
import { cleanupTmp, execShellAsync } from '$lib/api/common';

export async function post(request: Request) {
	const { email, userName, userPassword } = request.body;
	let { baseURL } = request.body;
	const traefikURL = baseURL;
	baseURL = `https://${baseURL}`;
	const deployId = 'plausible';
	const workdir = '/tmp/plausible';
	const secretKey = generator.generate({ length: 64, numbers: true, strict: true });
	const generateEnvsPostgres = {
		POSTGRESQL_PASSWORD: generator.generate({ length: 24, numbers: true, strict: true }),
		POSTGRESQL_USERNAME: generator.generate({ length: 10, numbers: true, strict: true }),
		POSTGRESQL_DATABASE: 'plausible'
	};

	const secrets = [
		{ name: 'ADMIN_USER_EMAIL', value: email },
		{ name: 'ADMIN_USER_NAME', value: userName },
		{ name: 'ADMIN_USER_PWD', value: userPassword },
		{ name: 'BASE_URL', value: baseURL },
		{ name: 'SECRET_KEY_BASE', value: secretKey },
		{ name: 'DISABLE_AUTH', value: 'false' },
		{ name: 'DISABLE_REGISTRATION', value: 'true' },
		{
			name: 'DATABASE_URL',
			value: `postgresql://${generateEnvsPostgres.POSTGRESQL_USERNAME}:${generateEnvsPostgres.POSTGRESQL_PASSWORD}@plausible_db:5432/${generateEnvsPostgres.POSTGRESQL_DATABASE}`
		},
		{ name: 'CLICKHOUSE_DATABASE_URL', value: 'http://plausible_events_db:8123/plausible' }
	];

	const generateEnvsClickhouse = {};
	for (const secret of secrets) generateEnvsClickhouse[secret.name] = secret.value;

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

	const clickhouseConfigs = [
		{
			source: 'plausible-clickhouse-user-config.xml',
			target: '/etc/clickhouse-server/users.d/logging.xml'
		},
		{
			source: 'plausible-clickhouse-config.xml',
			target: '/etc/clickhouse-server/config.d/logging.xml'
		},
		{ source: 'plausible-init.query', target: '/docker-entrypoint-initdb.d/init.query' },
		{ source: 'plausible-init-db.sh', target: '/docker-entrypoint-initdb.d/init-db.sh' }
	];

	const initQuery = 'CREATE DATABASE IF NOT EXISTS plausible;';
	const initScript = 'clickhouse client --queries-file /docker-entrypoint-initdb.d/init.query';
	await execShellAsync(`mkdir -p ${workdir}`);
	await fs.writeFile(`${workdir}/clickhouse-config.xml`, clickhouseConfigXml);
	await fs.writeFile(`${workdir}/clickhouse-user-config.xml`, clickhouseUserConfigXml);
	await fs.writeFile(`${workdir}/init.query`, initQuery);
	await fs.writeFile(`${workdir}/init-db.sh`, initScript);
	const stack = {
		version: '3.8',
		services: {
			[deployId]: {
				image: 'plausible/analytics:latest',
				command:
					'sh -c "sleep 10 && /entrypoint.sh db createdb && /entrypoint.sh db migrate && /entrypoint.sh db init-admin && /entrypoint.sh run"',
				networks: [`${docker.network}`],
				volumes: [`${deployId}-postgres-data:/var/lib/postgresql/data`],
				environment: generateEnvsClickhouse,
				deploy: {
					...baseServiceConfiguration,
					labels: [
						'managedBy=coolify',
						'type=service',
						'serviceName=plausible',
						'configuration=' +
							JSON.stringify({
								email,
								userName,
								userPassword,
								baseURL,
								secretKey,
								generateEnvsPostgres,
								generateEnvsClickhouse
							}),
						'traefik.enable=true',
						'traefik.http.services.' + deployId + '.loadbalancer.server.port=8000',
						'traefik.http.routers.' + deployId + '.entrypoints=websecure',
						'traefik.http.routers.' +
							deployId +
							'.rule=Host(`' +
							traefikURL +
							'`) && PathPrefix(`/`)',
						'traefik.http.routers.' + deployId + '.tls.certresolver=letsencrypt',
						'traefik.http.routers.' + deployId + '.middlewares=global-compress'
					]
				}
			},
			plausible_db: {
				image: 'bitnami/postgresql:13.2.0',
				networks: [`${docker.network}`],
				environment: generateEnvsPostgres,
				deploy: {
					...baseServiceConfiguration,
					labels: ['managedBy=coolify', 'type=service', 'serviceName=plausible']
				}
			},
			plausible_events_db: {
				image: 'yandex/clickhouse-server:21.3.2.5',
				networks: [`${docker.network}`],
				volumes: [`${deployId}-clickhouse-data:/var/lib/clickhouse`],
				ulimits: {
					nofile: {
						soft: 262144,
						hard: 262144
					}
				},
				configs: [...clickhouseConfigs],
				deploy: {
					...baseServiceConfiguration,
					labels: ['managedBy=coolify', 'type=service', 'serviceName=plausible']
				}
			}
		},
		networks: {
			[`${docker.network}`]: {
				external: true
			}
		},
		volumes: {
			[`${deployId}-clickhouse-data`]: {
				external: true
			},
			[`${deployId}-postgres-data`]: {
				external: true
			}
		},
		configs: {
			'plausible-clickhouse-user-config.xml': {
				file: `${workdir}/clickhouse-user-config.xml`
			},
			'plausible-clickhouse-config.xml': {
				file: `${workdir}/clickhouse-config.xml`
			},
			'plausible-init.query': {
				file: `${workdir}/init.query`
			},
			'plausible-init-db.sh': {
				file: `${workdir}/init-db.sh`
			}
		}
	};
	await fs.writeFile(`${workdir}/stack.yml`, yaml.dump(stack));
	await execShellAsync('docker stack rm plausible');
	await execShellAsync(`cat ${workdir}/stack.yml | docker stack deploy --prune -c - ${deployId}`);
	cleanupTmp(workdir);
	return {
		status: 200,
		body: { message: 'OK' }
	};
}
