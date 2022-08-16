import { createDirectories, getServiceFromDB, getServiceImage, getServiceMainPort, makeLabelForServices } from "./common";

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

export function defaultServiceComposeConfiguration(network: string): any {
    return {
        networks: [network],
        restart: 'always',
        deploy: {
            restart_policy: {
                condition: 'on-failure',
                delay: '10s',
                max_attempts: 10,
                window: '120s'
            }
        }
    }
}