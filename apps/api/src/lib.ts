import { decrypt, encrypt, prisma } from "./lib/common";
import { includeServices } from "./lib/services/common";


export async function migrateServicesToNewTemplate() {
    // This function migrates old hardcoded services to the new template based services
    try {
        const services = await prisma.service.findMany({ include: includeServices })
        for (const service of services) {
            if (service.type === 'plausibleanalytics' && service.plausibleAnalytics) await plausibleAnalytics(service)
            if (service.type === 'fider' && service.fider) await fider(service)
            if (service.type === 'minio' && service.minio) await minio(service)
            if (service.type === 'vscodeserver' && service.vscodeserver) await vscodeserver(service)
            if (service.type === 'wordpress' && service.wordpress) await wordpress(service)

        }
    } catch (error) {
        console.log(error)

    }
}
async function wordpress(service: any) {
    const { extraConfig, tablePrefix, ownMysql, mysqlHost, mysqlPort, mysqlUser, mysqlPassword, mysqlRootUser, mysqlRootUserPassword, mysqlDatabase, ftpEnabled, ftpUser, ftpPassword, ftpPublicPort, ftpHostKey, ftpHostKeyPrivate } = service.wordpress

    const secrets = [
        `MYSQL_ROOT_PASSWORD@@@${mysqlRootUserPassword}`,
        `MYSQL_PASSWORD@@@${mysqlPassword}`,
        ftpPassword && `FTP_PASSWORD@@@${ftpPassword}`,
        ftpHostKeyPrivate && `FTP_HOST_KEY_PRIVATE@@@${ftpHostKeyPrivate}`,
        ftpHostKey && `FTP_HOST_KEY@@@${ftpHostKey}`,
    ]
    const settings = [
        `MYSQL_ROOT_USER@@@${mysqlRootUser}`,
        `MYSQL_USER@@@${mysqlUser}`,
        `MYSQL_DATABASE@@@${mysqlDatabase}`,
        `MYSQL_HOST@@@${mysqlHost}`,
        `MYSQL_PORT@@@${mysqlPort}`,
        `FTP_ENABLED@@@${ftpEnabled}`,
        `FTP_USER@@@${ftpUser}`,
        `FTP_PUBLIC_PORT@@@${ftpPublicPort}`,
        `WORDPRESS_CONFIG_EXTRA@@@${extraConfig}`,
        `WORDPRESS_TABLE_PREFIX@@@${tablePrefix}`,
        `OWN_MYSQL@@@${ownMysql}`,

    ]
    await migrateSecrets(secrets, service);
    await migrateSettings(settings, service);

    // Remove old service data
    // await prisma.service.update({ where: { id: service.id }, data: { wordpress: { delete: true } } })
}
async function vscodeserver(service: any) {
    const { password } = service.vscodeserver

    const secrets = [
        `PASSWORD@@@${password}`,
    ]
    await migrateSecrets(secrets, service);

    // Remove old service data
    // await prisma.service.update({ where: { id: service.id }, data: { vscodeserver: { delete: true } } })
}
async function minio(service: any) {
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
    await migrateSettings(settings, service);
    await migrateSecrets(secrets, service);

    // Remove old service data
    // await prisma.service.update({ where: { id: service.id }, data: { minio: { delete: true } } })
}
async function fider(service: any) {
    const { postgresqlUser, postgresqlPassword, postgresqlDatabase, jwtSecret, emailNoreply, emailMailgunApiKey, emailMailgunDomain, emailMailgunRegion, emailSmtpHost, emailSmtpPort, emailSmtpUser, emailSmtpPassword, emailSmtpEnableStartTls } = service.fider

    const secrets = [
        `JWT_SECRET@@@${jwtSecret}`,
        emailMailgunApiKey && `EMAIL_MAILGUN_API_KEY@@@${emailMailgunApiKey}`,
        emailSmtpPassword && `EMAIL_SMTP_PASSWORD@@@${emailSmtpPassword}`,
        `POSTGRES_PASSWORD@@@${postgresqlPassword}`,
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
    await migrateSettings(settings, service);
    await migrateSecrets(secrets, service);

    // Remove old service data
    // await prisma.service.update({ where: { id: service.id }, data: { fider: { delete: true } } })

}
async function plausibleAnalytics(service: any) {
    const { email = 'admin@example.com', username = 'admin', password, postgresqlUser, postgresqlPassword, postgresqlDatabase, secretKeyBase, scriptName } = service.plausibleAnalytics;

    const settings = [
        `BASE_URL@@@$$generate_fqdn`,
        `ADMIN_USER_EMAIL@@@${email}`,
        `ADMIN_USER_NAME@@@${username}`,
        `DISABLE_AUTH@@@false`,
        `DISABLE_REGISTRATION@@@true`,
        `POSTGRESQL_USER@@@${postgresqlUser}`,
        `POSTGRESQL_DATABASE@@@${postgresqlDatabase}`,
        `SCRIPT_NAME@@@${scriptName}`,
    ]
    const secrets = [
        `ADMIN_USER_PWD@@@${password}`,
        `SECRET_KEY_BASE@@@${secretKeyBase}`,
        `POSTGRES_PASSWORD@@@${postgresqlPassword}`,
        `DATABASE_URL@@@${encrypt(`postgres://${postgresqlUser}:${decrypt(postgresqlPassword)}@$$generate_fqdn:5432/${postgresqlDatabase}`)}`,
    ]
    const volumes = [
        `${service.id}-postgresql-data@@@/bitnami/postgresql@@@${service.id}-postgresql`,
        `${service.id}-clickhouse-data@@@/var/lib/clickhouse/data@@@${service.id}-clickhouse`,
    ]
    await migrateSettings(settings, service);
    await migrateSecrets(secrets, service);
    await createVolumes(volumes, service);

    // Remove old service data
    // await prisma.service.update({ where: { id: service.id }, data: { plausibleAnalytics: { delete: true } } })
}

async function migrateSettings(settings: any[], service: any) {
    for (const setting of settings) {
        if (!setting) continue;
        const [name, value] = setting.split('@@@')
        console.log('Migrating setting', name, value)
        await prisma.serviceSetting.findFirst({ where: { name, serviceId: service.id } }) || await prisma.serviceSetting.create({ data: { name, value, service: { connect: { id: service.id } } } })
    }
}
async function migrateSecrets(secrets: any[], service: any) {
    for (const secret of secrets) {
        if (!secret) continue;
        const [name, value] = secret.split('@@@')
        console.log('Migrating secret', name, value)
        await prisma.serviceSecret.findFirst({ where: { name, serviceId: service.id } }) || await prisma.serviceSecret.create({ data: { name, value, service: { connect: { id: service.id } } } })
    }
}
async function createVolumes(volumes: any[], service: any) {
    for (const volume of volumes) {
        const [volumeName, path, containerId] = volume.split('@@@')
        await prisma.servicePersistentStorage.findFirst({ where: { volumeName, serviceId: service.id } }) || await prisma.servicePersistentStorage.create({ data: { volumeName, path, containerId, predefined: true, service: { connect: { id: service.id } } } })
    }
}