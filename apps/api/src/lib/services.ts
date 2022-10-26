import { createDirectories, getServiceFromDB, getServiceImage, getServiceMainPort, isDev, makeLabelForServices } from "./common";
import fs from 'fs/promises';
export async function getTemplates() {
    let templates: any = [];
    if (isDev) {
        templates = JSON.parse(await (await fs.readFile('./template.json')).toString())
    } else {
        templates = JSON.parse(await (await fs.readFile('/app/template.json')).toString())
    }
    // if (!isDev) {
    //     templates.push({
    //         "templateVersion": "1.0.0",
    //         "defaultVersion": "latest",
    //         "name": "Test-Fake-Service",
    //         "description": "",
    //         "services": {
    //             "$$id": {
    //                 "name": "Test-Fake-Service",
    //                 "depends_on": [
    //                     "$$id-postgresql",
    //                     "$$id-redis"
    //                 ],
    //                 "image": "weblate/weblate:$$core_version",
    //                 "volumes": [
    //                     "$$id-data:/app/data",
    //                 ],
    //                 "environment": [
    //                     `POSTGRES_SECRET=$$secret_postgres_secret`,
    //                     `WEBLATE_SITE_DOMAIN=$$config_weblate_site_domain`,
    //                     `WEBLATE_ADMIN_PASSWORD=$$secret_weblate_admin_password`,
    //                     `POSTGRES_PASSWORD=$$secret_postgres_password`,
    //                     `POSTGRES_USER=$$config_postgres_user`,
    //                     `POSTGRES_DATABASE=$$config_postgres_db`,
    //                     `POSTGRES_HOST=$$id-postgresql`,
    //                     `POSTGRES_PORT=5432`,
    //                     `REDIS_HOST=$$id-redis`,
    //                 ],
    //                 "ports": [
    //                     "8080"
    //                 ]
    //             },
    //             "$$id-postgresql": {
    //                 "name": "PostgreSQL",
    //                 "depends_on": [],
    //                 "image": "postgres:14-alpine",
    //                 "volumes": [
    //                     "$$id-postgresql-data:/var/lib/postgresql/data",
    //                 ],
    //                 "environment": [
    //                     "POSTGRES_USER=$$config_postgres_user",
    //                     "POSTGRES_PASSWORD=$$secret_postgres_password",
    //                     "POSTGRES_DB=$$config_postgres_db",
    //                 ],
    //                 "ports": []
    //             },
    //             "$$id-redis": {
    //                 "name": "Redis",
    //                 "depends_on": [],
    //                 "image": "redis:7-alpine",
    //                 "volumes": [
    //                     "$$id-redis-data:/data",
    //                 ],
    //                 "environment": [],
    //                 "ports": [],
    //             }
    //         },
    //         "variables": [
    //             {
    //                 "id": "$$config_weblate_site_domain",
    //                 "main": "$$id",
    //                 "name": "WEBLATE_SITE_DOMAIN",
    //                 "label": "Weblate Domain",
    //                 "defaultValue": "$$generate_domain",
    //                 "description": "",
    //             },
    //             {
    //                 "id": "$$secret_weblate_admin_password",
    //                 "main": "$$id",
    //                 "name": "WEBLATE_ADMIN_PASSWORD",
    //                 "label": "Weblate Admin Password",
    //                 "defaultValue": "$$generate_password",
    //                 "description": "",
    //                 "extras": {
    //                     "isVisibleOnUI": true,
    //                 }
    //             },
    //             {
    //                 "id": "$$secret_weblate_admin_password2",
    //                 "name": "WEBLATE_ADMIN_PASSWORD2",
    //                 "label": "Weblate Admin Password2",
    //                 "defaultValue": "$$generate_password",
    //                 "description": "",
    //             },
    //             {
    //                 "id": "$$config_postgres_user",
    //                 "main": "$$id-postgresql",
    //                 "name": "POSTGRES_USER",
    //                 "label": "PostgreSQL User",
    //                 "defaultValue": "$$generate_username",
    //                 "description": "",
    //             },
    //             {
    //                 "id": "$$secret_postgres_password",
    //                 "main": "$$id-postgresql",
    //                 "name": "POSTGRES_PASSWORD",
    //                 "label": "PostgreSQL Password",
    //                 "defaultValue": "$$generate_password(32)",
    //                 "description": "",
    //             },
    //             {
    //                 "id": "$$secret_postgres_password_hex32",
    //                 "name": "POSTGRES_PASSWORD_hex32",
    //                 "label": "PostgreSQL Password hex32",
    //                 "defaultValue": "$$generate_hex(32)",
    //                 "description": "",
    //             },
    //             {
    //                 "id": "$$config_postgres_something_hex32",
    //                 "name": "POSTGRES_SOMETHING_HEX32",
    //                 "label": "PostgreSQL Something hex32",
    //                 "defaultValue": "$$generate_hex(32)",
    //                 "description": "",
    //             },
    //             {
    //                 "id": "$$config_postgres_db",
    //                 "main": "$$id-postgresql",
    //                 "name": "POSTGRES_DB",
    //                 "label": "PostgreSQL Database",
    //                 "defaultValue": "weblate",
    //                 "description": "",
    //             },
    //             {
    //                 "id": "$$secret_postgres_secret",
    //                 "name": "POSTGRES_SECRET",
    //                 "label": "PostgreSQL Secret",
    //                 "defaultValue": "",
    //                 "description": "",
    //             },
    //         ]
    //     })
    // }
    return templates
}
export async function defaultServiceConfigurations({ id, teamId }) {
    const service = await getServiceFromDB({ id, teamId });
    const { destinationDockerId, destinationDocker, type, serviceSecret } = service;

    const network = destinationDockerId && destinationDocker.network;
    const port = getServiceMainPort(type);

    const { workdir } = await createDirectories({ repository: type, buildId: id });

    const image = getServiceImage(type);
    let secrets = [];
    if (serviceSecret.length > 0) {
        serviceSecret.forEach((secret) => {
            secrets.push(`${secret.name}=${secret.value}`);
        });
    }
    return { ...service, network, port, workdir, image, secrets }
}