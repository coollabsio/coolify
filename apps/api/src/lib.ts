import cuid from "cuid";
import { decrypt, encrypt, fixType, generatePassword, generateToken, prisma } from "./lib/common";
import { getTemplates } from "./lib/services";

export async function migrateApplicationPersistentStorage() {
    const settings = await prisma.setting.findFirst()
    if (settings) {
        const { id: settingsId, applicationStoragePathMigrationFinished } = settings
        try {
            if (!applicationStoragePathMigrationFinished) {
                const applications = await prisma.application.findMany({ include: { persistentStorage: true } });
                for (const application of applications) {
                    if (application.persistentStorage && application.persistentStorage.length > 0 && application?.buildPack !== 'docker') {
                        for (const storage of application.persistentStorage) {
                            let { id, path } = storage
                            if (!path.startsWith('/app')) {
                                path = `/app${path}`
                                await prisma.applicationPersistentStorage.update({ where: { id }, data: { path, oldPath: true } })
                            }
                        }
                    }
                }
            }
        } catch (error) {
            console.log(error)
        } finally {
            await prisma.setting.update({ where: { id: settingsId }, data: { applicationStoragePathMigrationFinished: true } })
        }
    }
}
export async function migrateServicesToNewTemplate() {
    // This function migrates old hardcoded services to the new template based services
    try {
        let templates = await getTemplates()
        const services: any = await prisma.service.findMany({
            include: {
                destinationDocker: true,
                persistentStorage: true,
                serviceSecret: true,
                serviceSetting: true,
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
                taiga: true,
            }
        })
        for (const service of services) {
            try {
                const { id } = service
                if (!service.type) {
                    continue;
                }
                let template = templates.find(t => fixType(t.type) === fixType(service.type));
                if (template) {
                    template = JSON.parse(JSON.stringify(template).replaceAll('$$id', service.id))
                    if (service.type === 'plausibleanalytics' && service.plausibleAnalytics) await plausibleAnalytics(service, template)
                    if (service.type === 'fider' && service.fider) await fider(service, template)
                    if (service.type === 'minio' && service.minio) await minio(service, template)
                    if (service.type === 'vscodeserver' && service.vscodeserver) await vscodeserver(service, template)
                    if (service.type === 'wordpress' && service.wordpress) await wordpress(service, template)
                    if (service.type === 'ghost' && service.ghost) await ghost(service, template)
                    if (service.type === 'meilisearch' && service.meiliSearch) await meilisearch(service, template)
                    if (service.type === 'umami' && service.umami) await umami(service, template)
                    if (service.type === 'hasura' && service.hasura) await hasura(service, template)
                    if (service.type === 'glitchTip' && service.glitchTip) await glitchtip(service, template)
                    if (service.type === 'searxng' && service.searxng) await searxng(service, template)
                    if (service.type === 'weblate' && service.weblate) await weblate(service, template)
                    if (service.type === 'appwrite' && service.appwrite) await appwrite(service, template)

                    try {
                        await createVolumes(service, template);
                    } catch (error) {
                        console.log(error)
                    }
                    if (template.variables) {
                        if (template.variables.length > 0) {
                            for (const variable of template.variables) {
                                const { defaultValue } = variable;
                                const regex = /^\$\$.*\((\d+)\)$/g;
                                const length = Number(regex.exec(defaultValue)?.[1]) || undefined
                                if (variable.defaultValue.startsWith('$$generate_password')) {
                                    variable.value = generatePassword({ length });
                                } else if (variable.defaultValue.startsWith('$$generate_hex')) {
                                    variable.value = generatePassword({ length, isHex: true });
                                } else if (variable.defaultValue.startsWith('$$generate_username')) {
                                    variable.value = cuid();
                                } else if (variable.defaultValue.startsWith('$$generate_token')) {
                                    variable.value = generateToken()
                                } else {
                                    variable.value = variable.defaultValue || '';
                                }
                            }
                        }
                        for (const variable of template.variables) {
                            if (variable.id.startsWith('$$secret_')) {
                                const found = await prisma.serviceSecret.findFirst({ where: { name: variable.name, serviceId: id } })
                                if (!found) {
                                    await prisma.serviceSecret.create({
                                        data: { name: variable.name, value: encrypt(variable.value) || '', service: { connect: { id } } }
                                    })
                                }

                            }
                            if (variable.id.startsWith('$$config_')) {
                                const found = await prisma.serviceSetting.findFirst({ where: { name: variable.name, serviceId: id } })
                                if (!found) {
                                    await prisma.serviceSetting.create({
                                        data: { name: variable.name, value: variable.value.toString(), variableName: variable.id, service: { connect: { id } } }
                                    })
                                }
                            }
                        }
                    }
                    for (const s of Object.keys(template.services)) {
                        if (service.type === 'plausibleanalytics') {
                            continue;
                        }
                        if (template.services[s].volumes) {
                            for (const volume of template.services[s].volumes) {
                                const [volumeName, path] = volume.split(':')
                                if (!volumeName.startsWith('/')) {
                                    const found = await prisma.servicePersistentStorage.findFirst({ where: { volumeName, serviceId: id } })
                                    if (!found) {
                                        await prisma.servicePersistentStorage.create({
                                            data: { volumeName, path, containerId: s, predefined: true, service: { connect: { id } } }
                                        });
                                    }
                                }
                            }
                        }
                    }
                    await prisma.service.update({ where: { id }, data: { templateVersion: template.templateVersion } })
                }
            } catch (error) {
                console.log(error)
            }
        }
    } catch (error) {
        console.log(error)

    }
}
async function appwrite(service: any, template: any) {
    const { opensslKeyV1, executorSecret, mariadbHost, mariadbPort, mariadbUser, mariadbPassword, mariadbRootUserPassword, mariadbDatabase } = service.appwrite

    const secrets = [
        `_APP_EXECUTOR_SECRET@@@${executorSecret}`,
        `_APP_OPENSSL_KEY_V1@@@${opensslKeyV1}`,
        `_APP_DB_PASS@@@${mariadbPassword}`,
        `_APP_DB_ROOT_PASS@@@${mariadbRootUserPassword}`,
    ]

    const settings = [
        `_APP_DB_HOST@@@${mariadbHost}`,
        `_APP_DB_PORT@@@${mariadbPort}`,
        `_APP_DB_USER@@@${mariadbUser}`,
        `_APP_DB_SCHEMA@@@${mariadbDatabase}`,
    ]
    await migrateSecrets(secrets, service);
    await migrateSettings(settings, service, template);

    // Disconnect old service data
    // await prisma.service.update({ where: { id: service.id }, data: { appwrite: { disconnect: true } } })
}
async function weblate(service: any, template: any) {
    const { adminPassword, postgresqlUser, postgresqlPassword, postgresqlDatabase } = service.weblate

    const secrets = [
        `WEBLATE_ADMIN_PASSWORD@@@${adminPassword}`,
        `POSTGRES_PASSWORD@@@${postgresqlPassword}`,
    ]

    const settings = [
        `WEBLATE_SITE_DOMAIN@@@$$generate_domain`,
        `POSTGRES_USER@@@${postgresqlUser}`,
        `POSTGRES_DATABASE@@@${postgresqlDatabase}`,
        `POSTGRES_DB@@@${postgresqlDatabase}`,
        `POSTGRES_HOST@@@$$id-postgres`,
        `POSTGRES_PORT@@@5432`,
        `REDIS_HOST@@@$$id-redis`,
    ]
    await migrateSettings(settings, service, template);
    await migrateSecrets(secrets, service);

    // Disconnect old service data
    // await prisma.service.update({ where: { id: service.id }, data: { weblate: { disconnect: true } } })
}
async function searxng(service: any, template: any) {
    const { secretKey, redisPassword } = service.searxng

    const secrets = [
        `SECRET_KEY@@@${secretKey}`,
        `REDIS_PASSWORD@@@${redisPassword}`,
    ]

    const settings = [
        `SEARXNG_BASE_URL@@@$$generate_fqdn`
    ]
    await migrateSettings(settings, service, template);
    await migrateSecrets(secrets, service);

    // Disconnect old service data
    // await prisma.service.update({ where: { id: service.id }, data: { searxng: { disconnect: true } } })
}
async function glitchtip(service: any, template: any) {
    const { postgresqlUser, postgresqlPassword, postgresqlDatabase, secretKeyBase, defaultEmail, defaultUsername, defaultPassword, defaultEmailFrom, emailSmtpHost, emailSmtpPort, emailSmtpUser, emailSmtpPassword, emailSmtpUseTls, emailSmtpUseSsl, emailBackend, mailgunApiKey, sendgridApiKey, enableOpenUserRegistration } = service.glitchTip
    const { id } = service

    const secrets = [
        `POSTGRES_PASSWORD@@@${postgresqlPassword}`,
        `SECRET_KEY@@@${secretKeyBase}`,
        `MAILGUN_API_KEY@@@${mailgunApiKey}`,
        `SENDGRID_API_KEY@@@${sendgridApiKey}`,
        `DJANGO_SUPERUSER_PASSWORD@@@${defaultPassword}`,
        emailSmtpUser && emailSmtpPassword && emailSmtpHost && emailSmtpPort && `EMAIL_URL@@@${encrypt(`smtp://${emailSmtpUser}:${decrypt(emailSmtpPassword)}@${emailSmtpHost}:${emailSmtpPort}`)} || ''`,
        `DATABASE_URL@@@${encrypt(`postgres://${postgresqlUser}:${decrypt(postgresqlPassword)}@${id}-postgresql:5432/${postgresqlDatabase}`)}`,
        `REDIS_URL@@@${encrypt(`redis://${id}-redis:6379`)}`
    ]
    const settings = [
        `POSTGRES_USER@@@${postgresqlUser}`,
        `POSTGRES_DB@@@${postgresqlDatabase}`,
        `DEFAULT_FROM_EMAIL@@@${defaultEmailFrom}`,
        `EMAIL_USE_TLS@@@${emailSmtpUseTls}`,
        `EMAIL_USE_SSL@@@${emailSmtpUseSsl}`,
        `EMAIL_BACKEND@@@${emailBackend}`,
        `ENABLE_OPEN_USER_REGISTRATION@@@${enableOpenUserRegistration}`,
        `DJANGO_SUPERUSER_EMAIL@@@${defaultEmail}`,
        `DJANGO_SUPERUSER_USERNAME@@@${defaultUsername}`,
    ]
    await migrateSettings(settings, service, template);
    await migrateSecrets(secrets, service);

    await prisma.service.update({ where: { id: service.id }, data: { type: 'glitchtip' } })
    // Disconnect old service data
    // await prisma.service.update({ where: { id: service.id }, data: { glitchTip: { disconnect: true } } })
}
async function hasura(service: any, template: any) {
    const { postgresqlUser, postgresqlPassword, postgresqlDatabase, graphQLAdminPassword } = service.hasura
    const { id } = service

    const secrets = [
        `HASURA_GRAPHQL_ADMIN_SECRET@@@${graphQLAdminPassword}`,
        `HASURA_GRAPHQL_METADATA_DATABASE_URL@@@${encrypt(`postgres://${postgresqlUser}:${decrypt(postgresqlPassword)}@${id}-postgresql:5432/${postgresqlDatabase}`)}`,
        `POSTGRES_PASSWORD@@@${postgresqlPassword}`,
    ]
    const settings = [
        `POSTGRES_USER@@@${postgresqlUser}`,
        `POSTGRES_DB@@@${postgresqlDatabase}`,
    ]
    await migrateSettings(settings, service, template);
    await migrateSecrets(secrets, service);

    // Disconnect old service data
    // await prisma.service.update({ where: { id: service.id }, data: { hasura: { disconnect: true } } })
}
async function umami(service: any, template: any) {
    const { postgresqlUser, postgresqlPassword, postgresqlDatabase, umamiAdminPassword, hashSalt } = service.umami
    const { id } = service
    const secrets = [
        `HASH_SALT@@@${hashSalt}`,
        `POSTGRES_PASSWORD@@@${postgresqlPassword}`,
        `ADMIN_PASSWORD@@@${umamiAdminPassword}`,
        `DATABASE_URL@@@${encrypt(`postgres://${postgresqlUser}:${decrypt(postgresqlPassword)}@${id}-postgresql:5432/${postgresqlDatabase}`)}`,
    ]
    const settings = [
        `DATABASE_TYPE@@@postgresql`,
        `POSTGRES_USER@@@${postgresqlUser}`,
        `POSTGRES_DB@@@${postgresqlDatabase}`,
    ]
    await migrateSettings(settings, service, template);
    await migrateSecrets(secrets, service);

    await prisma.service.update({ where: { id: service.id }, data: { type: "umami-postgresql" } })

    // Disconnect old service data
    // await prisma.service.update({ where: { id: service.id }, data: { umami: { disconnect: true } } })
}
async function meilisearch(service: any, template: any) {
    const { masterKey } = service.meiliSearch

    const secrets = [
        `MEILI_MASTER_KEY@@@${masterKey}`,
    ]

    // await migrateSettings(settings, service, template);
    await migrateSecrets(secrets, service);

    // Disconnect old service data
    // await prisma.service.update({ where: { id: service.id }, data: { meiliSearch: { disconnect: true } } })
}
async function ghost(service: any, template: any) {
    const { defaultEmail, defaultPassword, mariadbUser, mariadbPassword, mariadbRootUser, mariadbRootUserPassword, mariadbDatabase } = service.ghost
    const { fqdn } = service

    const isHttps = fqdn.startsWith('https://');

    const secrets = [
        `GHOST_PASSWORD@@@${defaultPassword}`,
        `MARIADB_PASSWORD@@@${mariadbPassword}`,
        `MARIADB_ROOT_PASSWORD@@@${mariadbRootUserPassword}`,
        `GHOST_DATABASE_PASSWORD@@@${mariadbPassword}`,
    ]
    const settings = [
        `GHOST_EMAIL@@@${defaultEmail}`,
        `GHOST_DATABASE_HOST@@@${service.id}-mariadb`,
        `GHOST_DATABASE_USER@@@${mariadbUser}`,
        `GHOST_DATABASE_NAME@@@${mariadbDatabase}`,
        `GHOST_DATABASE_PORT_NUMBER@@@3306`,
        `MARIADB_USER@@@${mariadbUser}`,
        `MARIADB_DATABASE@@@${mariadbDatabase}`,
        `MARIADB_ROOT_USER@@@${mariadbRootUser}`,
        `GHOST_HOST@@@$$generate_domain`,
        `url@@@$$generate_fqdn`,
        `GHOST_ENABLE_HTTPS@@@${isHttps ? 'yes' : 'no'}`
    ]
    await migrateSettings(settings, service, template);
    await migrateSecrets(secrets, service);

    await prisma.service.update({ where: { id: service.id }, data: { type: "ghost-mariadb" } })

    // Disconnect old service data
    // await prisma.service.update({ where: { id: service.id }, data: { ghost: { disconnect: true } } })
}
async function wordpress(service: any, template: any) {
    const { extraConfig, tablePrefix, ownMysql, mysqlHost, mysqlPort, mysqlUser, mysqlPassword, mysqlRootUser, mysqlRootUserPassword, mysqlDatabase, ftpEnabled, ftpUser, ftpPassword, ftpPublicPort, ftpHostKey, ftpHostKeyPrivate } = service.wordpress

    let settings = []
    let secrets = []
    if (ownMysql) {
        secrets = [
            `WORDPRESS_DB_PASSWORD@@@${mysqlPassword}`,
            ftpPassword && `COOLIFY_FTP_PASSWORD@@@${ftpPassword}`,
            ftpHostKeyPrivate && `COOLIFY_FTP_HOST_KEY_PRIVATE@@@${ftpHostKeyPrivate}`,
            ftpHostKey && `COOLIFY_FTP_HOST_KEY@@@${ftpHostKey}`,
        ]
        settings = [
            `WORDPRESS_CONFIG_EXTRA@@@${extraConfig}`,
            `WORDPRESS_DB_HOST@@@${mysqlHost}`,
            `WORDPRESS_DB_PORT@@@${mysqlPort}`,
            `WORDPRESS_DB_USER@@@${mysqlUser}`,
            `WORDPRESS_DB_NAME@@@${mysqlDatabase}`,
        ]
    } else {
        secrets = [
            `MYSQL_ROOT_PASSWORD@@@${mysqlRootUserPassword}`,
            `MYSQL_PASSWORD@@@${mysqlPassword}`,
            ftpPassword && `COOLIFY_FTP_PASSWORD@@@${ftpPassword}`,
            ftpHostKeyPrivate && `COOLIFY_FTP_HOST_KEY_PRIVATE@@@${ftpHostKeyPrivate}`,
            ftpHostKey && `COOLIFY_FTP_HOST_KEY@@@${ftpHostKey}`,
        ]
        settings = [
            `MYSQL_ROOT_USER@@@${mysqlRootUser}`,
            `MYSQL_USER@@@${mysqlUser}`,
            `MYSQL_DATABASE@@@${mysqlDatabase}`,
            `MYSQL_HOST@@@${service.id}-mysql`,
            `MYSQL_PORT@@@${mysqlPort}`,
            `WORDPRESS_CONFIG_EXTRA@@@${extraConfig}`,
            `WORDPRESS_TABLE_PREFIX@@@${tablePrefix}`,
            `WORDPRESS_DB_HOST@@@${service.id}-mysql`,
            `COOLIFY_OWN_DB@@@${ownMysql}`,
            `COOLIFY_FTP_ENABLED@@@${ftpEnabled}`,
            `COOLIFY_FTP_USER@@@${ftpUser}`,
            `COOLIFY_FTP_PUBLIC_PORT@@@${ftpPublicPort}`,
        ]
    }

    await migrateSettings(settings, service, template);
    await migrateSecrets(secrets, service);
    if (ownMysql) {
        await prisma.service.update({ where: { id: service.id }, data: { type: "wordpress-only" } })
    }
    // Disconnect old service data
    // await prisma.service.update({ where: { id: service.id }, data: { wordpress: { disconnect: true } } })
}
async function vscodeserver(service: any, template: any) {
    const { password } = service.vscodeserver

    const secrets = [
        `PASSWORD@@@${password}`,
    ]
    await migrateSecrets(secrets, service);

    // Disconnect old service data
    // await prisma.service.update({ where: { id: service.id }, data: { vscodeserver: { disconnect: true } } })
}
async function minio(service: any, template: any) {
    const { rootUser, rootUserPassword, apiFqdn } = service.minio
    const secrets = [
        `MINIO_ROOT_PASSWORD@@@${rootUserPassword}`,
    ]
    const settings = [
        `MINIO_ROOT_USER@@@${rootUser}`,
        `MINIO_SERVER_URL@@@${apiFqdn}`,
        `MINIO_BROWSER_REDIRECT_URL@@@$$generate_fqdn`,
        `MINIO_DOMAIN@@@$$generate_domain`,
    ]
    await migrateSettings(settings, service, template);
    await migrateSecrets(secrets, service);

    // Disconnect old service data
    // await prisma.service.update({ where: { id: service.id }, data: { minio: { disconnect: true } } })
}
async function fider(service: any, template: any) {
    const { postgresqlUser, postgresqlPassword, postgresqlDatabase, jwtSecret, emailNoreply, emailMailgunApiKey, emailMailgunDomain, emailMailgunRegion, emailSmtpHost, emailSmtpPort, emailSmtpUser, emailSmtpPassword, emailSmtpEnableStartTls } = service.fider
    const { id } = service
    const secrets = [
        `JWT_SECRET@@@${jwtSecret}`,
        emailMailgunApiKey && `EMAIL_MAILGUN_API@@@${emailMailgunApiKey}`,
        emailSmtpPassword && `EMAIL_SMTP_PASSWORD@@@${emailSmtpPassword}`,
        `POSTGRES_PASSWORD@@@${postgresqlPassword}`,
        `DATABASE_URL@@@${encrypt(`postgresql://${postgresqlUser}:${decrypt(postgresqlPassword)}@${id}-postgresql:5432/${postgresqlDatabase}?sslmode=disable`)}`
    ]
    const settings = [
        `BASE_URL@@@$$generate_fqdn`,
        `EMAIL_NOREPLY@@@${emailNoreply || 'noreply@example.com'}`,
        `EMAIL_MAILGUN_DOMAIN@@@${emailMailgunDomain || ''}`,
        `EMAIL_MAILGUN_REGION@@@${emailMailgunRegion || ''}`,
        `EMAIL_SMTP_HOST@@@${emailSmtpHost || ''}`,
        `EMAIL_SMTP_PORT@@@${emailSmtpPort || 587}`,
        `EMAIL_SMTP_USER@@@${emailSmtpUser || ''}`,
        `EMAIL_SMTP_PASSWORD@@@${emailSmtpPassword || ''}`,
        `EMAIL_SMTP_ENABLE_STARTTLS@@@${emailSmtpEnableStartTls || 'false'}`,
        `POSTGRES_USER@@@${postgresqlUser}`,
        `POSTGRES_DB@@@${postgresqlDatabase}`,
    ]
    await migrateSettings(settings, service, template);
    await migrateSecrets(secrets, service);

    // Disconnect old service data
    // await prisma.service.update({ where: { id: service.id }, data: { fider: { disconnect: true } } })

}
async function plausibleAnalytics(service: any, template: any) {
    const { email, username, password, postgresqlUser, postgresqlPassword, postgresqlDatabase, secretKeyBase, scriptName } = service.plausibleAnalytics;
    const { id } = service

    const settings = [
        `BASE_URL@@@$$generate_fqdn`,
        `ADMIN_USER_EMAIL@@@${email}`,
        `ADMIN_USER_NAME@@@${username}`,
        `DISABLE_AUTH@@@false`,
        `DISABLE_REGISTRATION@@@true`,
        `POSTGRESQL_USERNAME@@@${postgresqlUser}`,
        `POSTGRESQL_DATABASE@@@${postgresqlDatabase}`,
        `SCRIPT_NAME@@@${scriptName}`,
    ]
    const secrets = [
        `ADMIN_USER_PWD@@@${password}`,
        `SECRET_KEY_BASE@@@${secretKeyBase}`,
        `POSTGRESQL_PASSWORD@@@${postgresqlPassword}`,
        `DATABASE_URL@@@${encrypt(`postgres://${postgresqlUser}:${decrypt(postgresqlPassword)}@${id}-postgresql:5432/${postgresqlDatabase}`)}`,
    ]
    await migrateSettings(settings, service, template);
    await migrateSecrets(secrets, service);

    // Disconnect old service data
    // await prisma.service.update({ where: { id: service.id }, data: { plausibleAnalytics: { disconnect: true } } })
}
async function migrateSettings(settings: any[], service: any, template: any) {
    for (const setting of settings) {
        try {
            if (!setting) continue;
            let [name, value] = setting.split('@@@')
            let minio = name
            if (name === 'MINIO_SERVER_URL') {
                name = 'coolify_fqdn_minio_console'
            }
            if (!value || value === 'null') {
                continue;
            }
            let variableName = template.variables.find((v: any) => v.name === name)?.id
            if (!variableName) {
                variableName = `$$config_${name.toLowerCase()}`
            }
            // console.log('Migrating setting', name, value, 'for service', service.id, ', service name:', service.name, 'variableName: ', variableName)

            await prisma.serviceSetting.findFirst({ where: { name: minio, serviceId: service.id } }) || await prisma.serviceSetting.create({ data: { name: minio, value, variableName, service: { connect: { id: service.id } } } })
        } catch (error) {
            console.log(error)
        }
    }
}
async function migrateSecrets(secrets: any[], service: any) {
    for (const secret of secrets) {
        try {
            if (!secret) continue;
            let [name, value] = secret.split('@@@')
            if (!value || value === 'null') {
                continue
            }
            // console.log('Migrating secret', name, value, 'for service', service.id, ', service name:', service.name)
            await prisma.serviceSecret.findFirst({ where: { name, serviceId: service.id } }) || await prisma.serviceSecret.create({ data: { name, value, service: { connect: { id: service.id } } } })
        } catch (error) {
            console.log(error)
        }
    }
}
async function createVolumes(service: any, template: any) {
    const volumes = [];
    for (const s of Object.keys(template.services)) {
        if (template.services[s].volumes && template.services[s].volumes.length > 0) {
            for (const volume of template.services[s].volumes) {
                let volumeName = volume.split(':')[0]
                const volumePath = volume.split(':')[1]
                let volumeService = s
                if (service.type === 'plausibleanalytics' && service.plausibleAnalytics?.id) {
                    let volumeId = volumeName.split('-')[0]
                    volumeName = volumeName.replace(volumeId, service.plausibleAnalytics.id)
                }
                volumes.push(`${volumeName}@@@${volumePath}@@@${volumeService}`)
            }
        }
    }
    for (const volume of volumes) {
        const [volumeName, path, containerId] = volume.split('@@@')
        // console.log('Creating volume', volumeName, path, containerId, 'for service', service.id, ', service name:', service.name)
        await prisma.servicePersistentStorage.findFirst({ where: { volumeName, serviceId: service.id } }) || await prisma.servicePersistentStorage.create({ data: { volumeName, path, containerId, predefined: true, service: { connect: { id: service.id } } } })
    }
}
