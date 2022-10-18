import { decrypt, encrypt, generatePassword, prisma } from "./lib/common";
import { includeServices } from "./lib/services/common";


export async function migrateServicesToNewTemplate() {
    // This function migrates old hardcoded services to the new template based services
    try {
        const services = await prisma.service.findMany({ include: includeServices })
        for (const service of services) {
            if (service.type === 'plausibleanalytics' && service.plausibleAnalytics) await plausibleAnalytics(service)

        }
    } catch (error) {
        console.log(error)

    }
}
async function n8n(service: any) {
}
async function plausibleAnalytics(service: any) {
    const { email = 'admin@example.com', username = 'admin', password, postgresqlUser, postgresqlPassword, postgresqlDatabase, secretKeyBase, scriptName } = service.plausibleAnalytics;

    // Migrate Secrets
    await prisma.serviceSecret.create({ data: { name: 'ADMIN_USER_PWD', value: password, service: { connect: { id: service.id } } } })
    await prisma.serviceSecret.create({ data: { name: 'SECRET_KEY_BASE', value: secretKeyBase, service: { connect: { id: service.id } } } })
    await prisma.serviceSecret.create({ data: { name: 'POSTGRESQL_PASSWORD', value: postgresqlPassword, service: { connect: { id: service.id } } } })
    await prisma.serviceSecret.create({ data: { name: 'DATABASE_URL', value: encrypt(`postgresql://${postgresqlUser}:${decrypt(postgresqlPassword)}@${service.id}-postgresql:5432/${postgresqlDatabase}`), service: { connect: { id: service.id } } } })
    await prisma.serviceSecret.create({ data: { name: 'CLICKHOUSE_DATABASE_URL', value: encrypt(`http://${service.id}-clickhouse:8123/plausible`), service: { connect: { id: service.id } } } })

    // Migrate Configs
    await prisma.serviceSetting.create({ data: { name: 'BASE_URL', value: '$$generate_fqdn', service: { connect: { id: service.id } } } })
    await prisma.serviceSetting.create({ data: { name: 'ADMIN_USER_EMAIL', value: email, service: { connect: { id: service.id } } } })
    await prisma.serviceSetting.create({ data: { name: 'ADMIN_USER_NAME', value: username, service: { connect: { id: service.id } } } })
    await prisma.serviceSetting.create({ data: { name: 'DISABLE_AUTH', value: 'false', service: { connect: { id: service.id } } } })
    await prisma.serviceSetting.create({ data: { name: 'DISABLE_REGISTRATION', value: 'true', service: { connect: { id: service.id } } } })
    await prisma.serviceSetting.create({ data: { name: 'POSTGRESQL_USERNAME', value: postgresqlUser, service: { connect: { id: service.id } } } })
    await prisma.serviceSetting.create({ data: { name: 'POSTGRESQL_DATABASE', value: postgresqlDatabase, service: { connect: { id: service.id } } } })
    await prisma.serviceSetting.create({ data: { name: 'SCRIPT_NAME', value: scriptName, service: { connect: { id: service.id } } } })

    // Create predefined persistent volumes
    await prisma.servicePersistentStorage.create({ data: { path: '/bitnami/postgresql', containerId: `${service.id}-postgresql`, volumeName: `${service.id}-postgresql-data`, predefined: true, service: { connect: { id: service.id } } } })
    await prisma.servicePersistentStorage.create({ data: { path: '/var/lib/clickhouse', containerId: `${service.id}-clickhouse`, volumeName: `${service.id}-clickhouse-data`, predefined: true, service: { connect: { id: service.id } } } })

    // Remove old service data
    await prisma.service.update({ where: { id: service.id }, data: { plausibleAnalytics: { delete: true } } })
}