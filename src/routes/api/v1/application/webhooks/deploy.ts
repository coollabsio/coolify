import type { Request } from '@sveltejs/kit';
import crypto from 'crypto'
import Deployment from '$models/Logs/Deployment';
import { docker } from '$lib/docker';
import { precheckDeployment, setDefaultConfiguration } from '$lib/api/applications/configuration';
import cloneRepository from '$lib/api/applications/cloneRepository';
import { cleanupTmp } from '$lib/common';
import queueAndBuild from '$lib/api/applications/queueAndBuild';
export async function post(request: Request) {
    let configuration;
    const { GITHUP_APP_WEBHOOK_SECRET } = process.env
    const hmac = crypto.createHmac('sha256', GITHUP_APP_WEBHOOK_SECRET)
    const digest = Buffer.from('sha256=' + hmac.update(JSON.stringify(request.body)).digest('hex'), 'utf8')
    const checksum = Buffer.from(request.headers['x-hub-signature-256'], 'utf8')
    if (checksum.length !== digest.length || !crypto.timingSafeEqual(digest, checksum)) {
        return {
            status: 500,
            body: {
                error: 'Invalid request'
            }
        };
    }

    if (request.headers['x-github-event'] !== 'push') {
        return {
            status: 500,
            body: {
                error: 'Not a push event.'
            }
        };
    }
    try {
        const services = (await docker.engine.listServices()).filter(r => r.Spec.Labels.managedBy === 'coolify' && r.Spec.Labels.type === 'application')

        configuration = services.find(r => {
          if (request.body.ref.startsWith('refs')) {
            const branch = request.body.ref.split('/')[2]
            if (
              JSON.parse(r.Spec.Labels.configuration).repository.id === request.body.repository.id &&
              JSON.parse(r.Spec.Labels.configuration).repository.branch === branch
            ) {
              return r
            }
          }
  
          return null
        })
        configuration = setDefaultConfiguration(JSON.parse(configuration.Spec.Labels.configuration))

        if (!configuration) {
            return {
                status: 500,
                body: {
                    error: 'Whaaat?'
                }
            };
        }
        await cloneRepository(configuration);
        const { foundService, imageChanged, configChanged, forceUpdate } = await precheckDeployment({
            services,
            configuration
        });
        if (foundService && !forceUpdate && !imageChanged && !configChanged) {
            cleanupTmp(configuration.general.workdir);
            return {
                status: 200,
                body: {
                    success: false,
                    message: 'Nothing changed, no need to redeploy.'
                }
            };
        }
        const alreadyQueued = await Deployment.find({
            repoId: configuration.repository.id,
            branch: configuration.repository.branch,
            organization: configuration.repository.organization,
            name: configuration.repository.name,
            domain: configuration.publish.domain,
            progress: { $in: ['queued', 'inprogress'] }
        });
        if (alreadyQueued.length > 0) {
            return {
                status: 200,
                body: {
                    success: false,
                    message: 'Already in the queue.'
                }
            };
        }
        queueAndBuild(configuration, imageChanged);
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
        return {
            status: 500,
            body: {
                error
            }
        };
    }
}
