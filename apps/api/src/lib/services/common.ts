
import cuid from 'cuid';
import { encrypt, generatePassword, prisma } from '../common';

export const includeServices: any = {
	destinationDocker: true,
	persistentStorage: true,
	serviceSecret: true,
	minio: true,
	plausibleAnalytics: true,
	vscodeserver: true,
	wordpress: true,
	ghost: true,
	meiliSearch: true,
	umami: true,
	hasura: true,
	fider: true,
	moodle: true,
	appwrite: true,
	glitchTip: true,
	searxng: true,
	weblate: true,
	taiga: true
};
export async function configureServiceType({
	id,
	type
}: {
	id: string;
	type: string;
}): Promise<void> {
	if (type === 'plausibleanalytics') {
		const password = encrypt(generatePassword({}));
		const postgresqlUser = cuid();
		const postgresqlPassword = encrypt(generatePassword({}));
		const postgresqlDatabase = 'plausibleanalytics';
		const secretKeyBase = encrypt(generatePassword({ length: 64 }));

		await prisma.service.update({
			where: { id },
			data: {
				type,
				plausibleAnalytics: {
					create: {
						postgresqlDatabase,
						postgresqlUser,
						postgresqlPassword,
						password,
						secretKeyBase
					}
				}
			}
		});
	} else if (type === 'nocodb') {
		await prisma.service.update({
			where: { id },
			data: { type }
		});
	} else if (type === 'minio') {
		const rootUser = cuid();
		const rootUserPassword = encrypt(generatePassword({}));
		await prisma.service.update({
			where: { id },
			data: { type, minio: { create: { rootUser, rootUserPassword } } }
		});
	} else if (type === 'vscodeserver') {
		const password = encrypt(generatePassword({}));
		await prisma.service.update({
			where: { id },
			data: { type, vscodeserver: { create: { password } } }
		});
	} else if (type === 'wordpress') {
		const mysqlUser = cuid();
		const mysqlPassword = encrypt(generatePassword({}));
		const mysqlRootUser = cuid();
		const mysqlRootUserPassword = encrypt(generatePassword({}));
		await prisma.service.update({
			where: { id },
			data: {
				type,
				wordpress: { create: { mysqlPassword, mysqlRootUserPassword, mysqlRootUser, mysqlUser } }
			}
		});
	} else if (type === 'vaultwarden') {
		await prisma.service.update({
			where: { id },
			data: {
				type
			}
		});
	} else if (type === 'languagetool') {
		await prisma.service.update({
			where: { id },
			data: {
				type
			}
		});
	} else if (type === 'n8n') {
		await prisma.service.update({
			where: { id },
			data: {
				type
			}
		});
	} else if (type === 'uptimekuma') {
		await prisma.service.update({
			where: { id },
			data: {
				type
			}
		});
	} else if (type === 'ghost') {
		const defaultEmail = `${cuid()}@example.com`;
		const defaultPassword = encrypt(generatePassword({}));
		const mariadbUser = cuid();
		const mariadbPassword = encrypt(generatePassword({}));
		const mariadbRootUser = cuid();
		const mariadbRootUserPassword = encrypt(generatePassword({}));

		await prisma.service.update({
			where: { id },
			data: {
				type,
				ghost: {
					create: {
						defaultEmail,
						defaultPassword,
						mariadbUser,
						mariadbPassword,
						mariadbRootUser,
						mariadbRootUserPassword
					}
				}
			}
		});
	} else if (type === 'meilisearch') {
		const masterKey = encrypt(generatePassword({ length: 32 }));
		await prisma.service.update({
			where: { id },
			data: {
				type,
				meiliSearch: { create: { masterKey } }
			}
		});
	} else if (type === 'umami') {
		const umamiAdminPassword = encrypt(generatePassword({}));
		const postgresqlUser = cuid();
		const postgresqlPassword = encrypt(generatePassword({}));
		const postgresqlDatabase = 'umami';
		const hashSalt = encrypt(generatePassword({ length: 64 }));
		await prisma.service.update({
			where: { id },
			data: {
				type,
				umami: {
					create: {
						umamiAdminPassword,
						postgresqlDatabase,
						postgresqlPassword,
						postgresqlUser,
						hashSalt
					}
				}
			}
		});
	} else if (type === 'hasura') {
		const postgresqlUser = cuid();
		const postgresqlPassword = encrypt(generatePassword({}));
		const postgresqlDatabase = 'hasura';
		const graphQLAdminPassword = encrypt(generatePassword({}));
		await prisma.service.update({
			where: { id },
			data: {
				type,
				hasura: {
					create: {
						postgresqlDatabase,
						postgresqlPassword,
						postgresqlUser,
						graphQLAdminPassword
					}
				}
			}
		});
	} else if (type === 'fider') {
		const postgresqlUser = cuid();
		const postgresqlPassword = encrypt(generatePassword({}));
		const postgresqlDatabase = 'fider';
		const jwtSecret = encrypt(generatePassword({ length: 64, symbols: true }));
		await prisma.service.update({
			where: { id },
			data: {
				type,
				fider: {
					create: {
						postgresqlDatabase,
						postgresqlPassword,
						postgresqlUser,
						jwtSecret
					}
				}
			}
		});
	} else if (type === 'moodle') {
		const defaultUsername = cuid();
		const defaultPassword = encrypt(generatePassword({}));
		const defaultEmail = `${cuid()} @example.com`;
		const mariadbUser = cuid();
		const mariadbPassword = encrypt(generatePassword({}));
		const mariadbDatabase = 'moodle_db';
		const mariadbRootUser = cuid();
		const mariadbRootUserPassword = encrypt(generatePassword({}));
		await prisma.service.update({
			where: { id },
			data: {
				type,
				moodle: {
					create: {
						defaultUsername,
						defaultPassword,
						defaultEmail,
						mariadbUser,
						mariadbPassword,
						mariadbDatabase,
						mariadbRootUser,
						mariadbRootUserPassword
					}
				}
			}
		});
	} else if (type === 'appwrite') {
		const opensslKeyV1 = encrypt(generatePassword({}));
		const executorSecret = encrypt(generatePassword({}));
		const redisPassword = encrypt(generatePassword({}));
		const mariadbHost = `${id}-mariadb`
		const mariadbUser = cuid();
		const mariadbPassword = encrypt(generatePassword({}));
		const mariadbDatabase = 'appwrite';
		const mariadbRootUser = cuid();
		const mariadbRootUserPassword = encrypt(generatePassword({}));
		await prisma.service.update({
			where: { id },
			data: {
				type,
				appwrite: {
					create: {
						opensslKeyV1,
						executorSecret,
						redisPassword,
						mariadbHost,
						mariadbUser,
						mariadbPassword,
						mariadbDatabase,
						mariadbRootUser,
						mariadbRootUserPassword
					}
				}
			}
		});
	} else if (type === 'glitchTip') {
		const defaultUsername = cuid();
		const defaultEmail = `${defaultUsername}@example.com`;
		const defaultPassword = encrypt(generatePassword({}));
		const postgresqlUser = cuid();
		const postgresqlPassword = encrypt(generatePassword({}));
		const postgresqlDatabase = 'glitchTip';
		const secretKeyBase = encrypt(generatePassword({ length: 64 }));

		await prisma.service.update({
			where: { id },
			data: {
				type,
				glitchTip: {
					create: {
						postgresqlDatabase,
						postgresqlUser,
						postgresqlPassword,
						secretKeyBase,
						defaultEmail,
						defaultUsername,
						defaultPassword,
					}
				}
			}
		});
	} else if (type === 'searxng') {
		const secretKey = encrypt(generatePassword({ length: 32, isHex: true }))
		const redisPassword = encrypt(generatePassword({}));
		await prisma.service.update({
			where: { id },
			data: {
				type,
				searxng: {
					create: {
						secretKey,
						redisPassword,
					}
				}
			}
		});
	} else if (type === 'weblate') {
		const adminPassword = encrypt(generatePassword({}))
		const postgresqlUser = cuid();
		const postgresqlPassword = encrypt(generatePassword({}));
		const postgresqlDatabase = 'weblate';
		await prisma.service.update({
			where: { id },
			data: {
				type,
				weblate: {
					create: {
						adminPassword,
						postgresqlHost: `${id}-postgresql`,
						postgresqlPort: 5432,
						postgresqlUser,
						postgresqlPassword,
						postgresqlDatabase,
					}
				}
			}
		});
	} else if (type === 'taiga') {
		const secretKey = encrypt(generatePassword({}))
		const erlangSecret = encrypt(generatePassword({}))
		const rabbitMQUser = cuid();
		const djangoAdminUser = cuid();
		const djangoAdminPassword = encrypt(generatePassword({}))
		const rabbitMQPassword = encrypt(generatePassword({}))
		const postgresqlUser = cuid();
		const postgresqlPassword = encrypt(generatePassword({}));
		const postgresqlDatabase = 'taiga';
		await prisma.service.update({
			where: { id },
			data: {
				type,
				taiga: {
					create: {
						secretKey,
						erlangSecret,
						djangoAdminUser,
						djangoAdminPassword,
						rabbitMQUser,
						rabbitMQPassword,
						postgresqlHost: `${id}-postgresql`,
						postgresqlPort: 5432,
						postgresqlUser,
						postgresqlPassword,
						postgresqlDatabase,
					}
				}
			}
		});
	} else if (type === 'grafana') {
		await prisma.service.update({
			where: { id },
			data: {
				type
			}
		});
	} else {
		await prisma.service.update({
			where: { id },
			data: {
				type
			}
		});
	}
}

export async function removeService({ id }: { id: string }): Promise<void> {
	await prisma.serviceSecret.deleteMany({ where: { serviceId: id } });
	await prisma.servicePersistentStorage.deleteMany({ where: { serviceId: id } });
	await prisma.meiliSearch.deleteMany({ where: { serviceId: id } });
	await prisma.fider.deleteMany({ where: { serviceId: id } });
	await prisma.ghost.deleteMany({ where: { serviceId: id } });
	await prisma.umami.deleteMany({ where: { serviceId: id } });
	await prisma.hasura.deleteMany({ where: { serviceId: id } });
	await prisma.plausibleAnalytics.deleteMany({ where: { serviceId: id } });
	await prisma.minio.deleteMany({ where: { serviceId: id } });
	await prisma.vscodeserver.deleteMany({ where: { serviceId: id } });
	await prisma.wordpress.deleteMany({ where: { serviceId: id } });
	await prisma.glitchTip.deleteMany({ where: { serviceId: id } });
	await prisma.moodle.deleteMany({ where: { serviceId: id } });
	await prisma.appwrite.deleteMany({ where: { serviceId: id } });
	await prisma.searxng.deleteMany({ where: { serviceId: id } });
	await prisma.weblate.deleteMany({ where: { serviceId: id } });
	await prisma.taiga.deleteMany({ where: { serviceId: id } });
	
	await prisma.service.delete({ where: { id } });
}