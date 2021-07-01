import Configuration from "$models/Configuration";
import { compareObjects, execShellAsync } from "../common";

export default async function (configuration) {
    /* 
    0 => nothing changed, no need to redeploy
    1 => force update 
    2 => configuration changed
    3 => continue normally
    */
    const currentConfiguration = await Configuration.findOne({
        'general.nickname': configuration.general.nickname
    })
    if (currentConfiguration) {
        // Base service configuration changed
        if (
            !currentConfiguration.build.container.baseSHA ||
            currentConfiguration.build.container.baseSHA !== configuration.build.container.baseSHA
        ) {
            return 1
        }

        // If the deployment is in error state, forceUpdate
        const state = await execShellAsync(
            `docker stack ps ${currentConfiguration.build.container.name} --format '{{ json . }}'`
        );
        const isError = state
            .split('\n')
            .filter((n) => n)
            .map((s) => JSON.parse(s))
            .filter(
                (n) =>
                    n.DesiredState !== 'Running' && n.Image.split(':')[1] === currentConfiguration.build.container.tag
            );
        if (isError.length > 0) {
            return 1
        }

        // If previewDeployments enabled
        if (
            currentConfiguration.general.isPreviewDeploymentEnabled &&
            currentConfiguration.general.pullRequest !== 0
        ) {
            return 1
        }
        // If build pack changed, forceUpdate the service
        if (currentConfiguration.build.pack !== configuration.build.pack) {
            return 1
        }

        const currentConfigurationCompare = JSON.parse(JSON.stringify(currentConfiguration));
        const configurationCompare = JSON.parse(JSON.stringify(configuration));
        delete currentConfigurationCompare.build.container;
        delete configurationCompare.build.container;

        if (
            !compareObjects(currentConfigurationCompare.build, configurationCompare.build) ||
            !compareObjects(currentConfigurationCompare.publish, configurationCompare.publish) ||
            currentConfigurationCompare.general.isPreviewDeploymentEnabled !==
            configurationCompare.general.isPreviewDeploymentEnabled
        ) {
            return 2
        }

        if (currentConfiguration.build.container.tag !== configuration.build.container.tag) {
            return 2
        }
        return 0
    }
    return 3
}