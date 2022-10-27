import { isDev } from "./common";
import fs from 'fs/promises';
export async function getTemplates() {
    let templates: any = [];
    if (isDev) {
        templates = JSON.parse(await (await fs.readFile('./templates.json')).toString())
    } else {
        templates = JSON.parse(await (await fs.readFile('/app/templates.json')).toString())
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
const compareSemanticVersions = (a: string, b: string) => {
    const a1 = a.split('.');
    const b1 = b.split('.');
    const len = Math.min(a1.length, b1.length);
    for (let i = 0; i < len; i++) {
        const a2 = +a1[ i ] || 0;
        const b2 = +b1[ i ] || 0;
        if (a2 !== b2) {
            return a2 > b2 ? 1 : -1;        
        }
    }
    return b1.length - a1.length;
};
export async function getTags(type?: string) {
    let tags: any = [];
    if (isDev) {
        tags = JSON.parse(await (await fs.readFile('./tags.json')).toString())
    } else {
        tags = JSON.parse(await (await fs.readFile('/app/tags.json')).toString())
    }
    tags = tags.find((tag: any) => tag.name.includes(type))
    tags.tags = tags.tags.sort(compareSemanticVersions).reverse();
    return  tags
}
