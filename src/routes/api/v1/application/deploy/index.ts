

import type { Request } from '@sveltejs/kit';

import Deployment from '$models/Logs/Deployment';
import ApplicationLog from '$models/Logs/Application';
import { verifyUserId } from '$lib/api/applications/common';
import * as cookie from 'cookie';
import { docker } from '$lib/docker';
import { precheckDeployment, setDefaultConfiguration } from '$lib/api/applications/configuration';
import cloneRepository from '$lib/api/applications/cloneRepository';
import { cleanupTmp } from '$lib/common';
import queueAndBuild from '$lib/api/applications/queueAndBuild';
export async function post(request: Request) {
    let configuration
    const { coolToken } = cookie.parse(request.headers.cookie || '');

    try {
        if (!await verifyUserId(coolToken)) {
            return {
                status: 500,
                body: {
                    error: 'Unauthorized.'
                }
            }
        }
        const services = (await docker.engine.listServices()).filter(r => r.Spec.Labels.managedBy === 'coolify' && r.Spec.Labels.type === 'application')
        configuration = setDefaultConfiguration(request.body)

        if (!configuration) {
            return {
                status: 500,
                body: {
                    error: 'Whaaat?'
                }
            }
        }
        await cloneRepository(configuration)
        const { foundService, imageChanged, configChanged, forceUpdate } = await precheckDeployment({ services, configuration })
        if (foundService && !forceUpdate && !imageChanged && !configChanged) {
            cleanupTmp(configuration.general.workdir)
            return {
                status: 200,
                body: {
                    success: false,
                    message: 'Nothing changed, no need to redeploy.'
                }
            }
        }
        const alreadyQueued = await Deployment.find({
            repoId: configuration.repository.id,
            branch: configuration.repository.branch,
            organization: configuration.repository.organization,
            name: configuration.repository.name,
            domain: configuration.publish.domain,
            progress: { $in: ['queued', 'inprogress'] }
        })
        if (alreadyQueued.length > 0) {
            return {
                status: 200,
                body: {
                    success: false,
                    message: 'Already in the queue.'
                }
            }
        }
        queueAndBuild(configuration, imageChanged)
        return {
            status: 200,
            body: {
                message: 'OK'
            }
        }
    } catch (error) {
        console.log(error)
        return {
            status: 500,
            body: {
                error
            }
        }

    }
    // try {
    //     const services = (await docker.engine.listServices()).filter(r => r.Spec.Labels.managedBy === 'coolify' && r.Spec.Labels.type === 'application')
    //     configuration = setDefaultConfiguration(request.body)
    //     if (!configuration) {
    //         return {
    //             status: 500,
    //             body: {
    //                 error: 'Whaat?'
    //             }
    //         }
    //     }
    //     await cloneRepository(configuration)
    //     const { foundService, imageChanged, configChanged, forceUpdate } = await precheckDeployment({ services, configuration })

    //     if (foundService && !forceUpdate && !imageChanged && !configChanged) {
    //         cleanupTmp(configuration.general.workdir)
    //         return {
    //             status: 500,
    //             body: {
    //                 error: 'Nothing changed, no need to redeploy.'
    //             }
    //         }
    //     }

    //     const alreadyQueued = await Deployment.find({
    //         repoId: configuration.repository.id,
    //         branch: configuration.repository.branch,
    //         organization: configuration.repository.organization,
    //         name: configuration.repository.name,
    //         domain: configuration.publish.domain,
    //         progress: { $in: ['queued', 'inprogress'] }
    //     })

    //     if (alreadyQueued.length > 0) {
    //         return {
    //             status: 200,
    //             body: {
    //                 message: 'Already in the queue.'
    //             }
    //         }
    //     }
    //     await queueAndBuild(configuration, imageChanged)
    //     return {
    //         status: 200,
    //         body: {
    //             message: 'Deployment queued.',
    //             nickname: configuration.general.nickname,
    //             name: configuration.build.container.name, deployId: configuration.general.deployId
    //         }
    //     }
    // } catch (error) {
    //     const { id, organization, name, branch } = configuration.repository
    //     const { domain } = configuration.publish
    //     const { deployId } = configuration.general
    //     await Deployment.findOneAndUpdate(
    //         { repoId: id, branch, deployId, organization, name, domain },
    //         { repoId: id, branch, deployId, organization, name, domain, progress: 'failed' })
    //     if (error.name) {
    //         if (error.message && error.stack) await saveServerLog(error)
    //         await new ApplicationLog({ repoId: id, branch, deployId, event: `[ERROR 😖]: ${error.stack}` }).save()
    //     }
    //     return {
    //         status: 200,
    //         body: {
    //             error
    //         }
    //     }
    // } finally {
    //     cleanupTmp(configuration.general.workdir)
    //     // await purgeImagesContainers(configuration)
    // }
}
