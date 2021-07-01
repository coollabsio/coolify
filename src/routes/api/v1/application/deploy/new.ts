import type { Request } from '@sveltejs/kit';
import Deployment from '$models/Deployment';
import { precheckDeployment, setDefaultConfiguration } from '$lib/api/applications/configuration';
import cloneRepository from '$lib/api/applications/cloneRepository';
import { cleanupTmp } from '$lib/api/common';
import queueAndBuild from '$lib/api/applications/queueAndBuild';
import Configuration from '$models/Configuration';

export async function post(request: Request) {
    const originalDomain = request.body.publish.domain
    const imageChanged = false
    const configuration = setDefaultConfiguration(request.body);
    if (!configuration) {
        return {
            status: 500,
            body: {
                error: 'Whaaat?'
            }
        };
    }
    try {
        await cloneRepository(configuration);
        const { id, organization, name, branch } = configuration.repository;
        const { domain } = configuration.publish;
        const { deployId, nickname } = configuration.general;

        await new Deployment({
            repoId: id,
            branch,
            deployId,
            domain,
            organization,
            name,
            nickname
        }).save();

        await Configuration.findOneAndUpdate(
            {
                'publish.domain': domain,
                'repository.id': id,
                'repository.organization': organization,
                'repository.name': name,
                'repository.branch': branch,
                'general.pullRequest': { $in: [null, 0] }
            },
            { ...configuration },
            { upsert: true, new: true }
        );

        queueAndBuild(configuration, imageChanged, originalDomain);
        return {
            status: 201,
            body: {
                message: 'Deployment queued.',
                nickname: configuration.general.nickname,
                name: configuration.build.container.name,
                deployId: configuration.general.deployId
            }
        };
    } catch (error) {
        console.log(error);
        await Deployment.findOneAndUpdate(
            {
                repoId: configuration.repository.id,
                branch: configuration.repository.branch,
                organization: configuration.repository.organization,
                name: configuration.repository.name,
                domain: configuration.publish.domain
            },
            {
                repoId: configuration.repository.id,
                branch: configuration.repository.branch,
                organization: configuration.repository.organization,
                name: configuration.repository.name,
                domain: configuration.publish.domain,
                progress: 'failed'
            }
        );
        return {
            status: 500,
            body: {
                error: error.message || error
            }
        };
    }
}
