import { createDirectories, getServiceFromDB, getServiceImage, getServiceMainPort, isDev, makeLabelForServices } from "./common";
import fs from 'fs/promises';
export async function getTemplates() {
    let templates = [];
    if (isDev) {
        templates = JSON.parse((await fs.readFile('./template.json')).toString())
    }

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