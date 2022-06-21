import {
	asyncExecShell,
	createDirectories,
	getDomain,
	getEngine,
	getUserDetails
} from '$lib/common';
import * as db from '$lib/database';
import { promises as fs } from 'fs';
import yaml from 'js-yaml';
import type { RequestHandler } from '@sveltejs/kit';
import { ErrorHandler, getServiceImage } from '$lib/database';
import { makeLabelForServices } from '$lib/buildPacks/common';
import type { ComposeFile } from '$lib/types/composeFile';
import type { Service, DestinationDocker, Prisma } from '@prisma/client';
import { getServiceMainPort } from '$lib/components/common';

export const post: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { id } = event.params;

	try {
		const service: Service & Prisma.ServiceInclude & { destinationDocker: DestinationDocker } =
			await db.getService({ id, teamId });
		const {
			type,
			version,
			fqdn,
			destinationDockerId,
			destinationDocker,
			serviceSecret,
			exposePort,
			calcom: {
				postgresqlUser,
				postgresqlPassword,
				postgresqlDatabase,
				emailFrom,
				emailSmtpHost,
				emailSmtpPort,
				emailSmtpUser,
				emailSmtpPassword,
				msGraphClientId,
				msGraphClientSecret,
				zoomClientId,
				zoomClientSecret,
				googleApiCredentials,
				licenseKey,
				authSecret,
				encryptionKey
			}
		} = service;
		const network = destinationDockerId && destinationDocker.network;
		const host = getEngine(destinationDocker.engine);
		const port = getServiceMainPort('calcom');

		const { workdir } = await createDirectories({ repository: type, buildId: id });
		const image = getServiceImage(type);
		const domain = getDomain(fqdn);
		const config = {
			calcom: {
				image: `${image}:${version}`,
				environmentVariables: {
					BASE_URL: domain,
					DATABASE_URL: `postgresql://${postgresqlUser}:${postgresqlPassword}@${id}-postgresql:5432/${postgresqlDatabase}?sslmode=disable`,
					EMAIL_FROM: emailFrom,
					EMAIL_SERVER_HOST: emailSmtpHost,
					EMAIL_SERVER_PORT: emailSmtpPort,
					EMAIL_SERVER_USER: emailSmtpUser,
					EMAIL_SERVER_PASSWORD: emailSmtpPassword,
					MS_GRAPH_CLIENT_ID: msGraphClientId,
					MS_GRAPH_CLIENT_SECRET: msGraphClientSecret,
					ZOOM_CLIENT_ID: zoomClientId,
					ZOOM_CLIENT_SECRET: zoomClientSecret,
					GOOGLE_API_CREDENTIALS: googleApiCredentials,
					NEXT_PUBLIC_WEBAPP_URL: domain,
					NEXT_PUBLIC_LICENSE_CONSENT: true,
					CALCOM_TELEMETRY_DISABLED: 1,
					CALCOM_LICENSE_KEY: licenseKey,
					NEXTAUTH_SECRET: `${authSecret.replace(/\$/g, '$$$')}`,
					CALENDSO_ENCRYPTION_KEY: `${encryptionKey.replace(/\$/g, '$$$')}`
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
				config.calcom.environmentVariables[secret.name] = secret.value;
			});
		}

		const composeFile: ComposeFile = {
			version: '3.8',
			services: {
				[id]: {
					container_name: id,
					image: config.calcom.image,
					environment: config.calcom.environmentVariables,
					networks: [network],
					volumes: [],
					restart: 'always',
					labels: makeLabelForServices('calcom'),
					...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
					deploy: {
						restart_policy: {
							condition: 'on-failure',
							delay: '5s',
							max_attempts: 3,
							window: '120s'
						}
					},
					depends_on: [`${id}-postgresql`]
				},
				[`${id}-postgresql`]: {
					image: config.postgresql.image,
					container_name: `${id}-postgresql`,
					environment: config.postgresql.environmentVariables,
					networks: [network],
					volumes: [config.postgresql.volume],
					restart: 'always',
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
				[config.postgresql.volume.split(':')[0]]: {
					name: config.postgresql.volume.split(':')[0]
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
			console.log(error);
			return ErrorHandler(error);
		}
	} catch (error) {
		return ErrorHandler(error);
	}
};
