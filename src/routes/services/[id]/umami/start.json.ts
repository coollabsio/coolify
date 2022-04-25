import { asyncExecShell, createDirectories, getEngine, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { promises as fs } from 'fs';
import yaml from 'js-yaml';
import type { RequestHandler } from '@sveltejs/kit';
import { ErrorHandler, getServiceImage } from '$lib/database';
import { makeLabelForServices } from '$lib/buildPacks/common';
import type { ComposeFile } from '$lib/types/composeFile';
import type { Service, DestinationDocker, Prisma } from '@prisma/client';
import bcrypt from 'bcryptjs';

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
			destinationDockerId,
			destinationDocker,
			serviceSecret,
			umami: {
				umamiAdminPassword,
				postgresqlUser,
				postgresqlPassword,
				postgresqlDatabase,
				hashSalt
			}
		} = service;
		const network = destinationDockerId && destinationDocker.network;
		const host = getEngine(destinationDocker.engine);

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
		const composeFile: ComposeFile = {
			version: '3.8',
			services: {
				[id]: {
					container_name: id,
					image: config.umami.image,
					environment: config.umami.environmentVariables,
					networks: [network],
					volumes: [],
					restart: 'always',
					labels: makeLabelForServices('umami'),
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
					build: workdir,
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
