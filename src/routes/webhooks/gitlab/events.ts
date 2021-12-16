import { getTeam, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';
import cuid from 'cuid';
import crypto from 'crypto'
import { buildQueue } from '$lib/queues';

export const post = async (request) => {
    const { event_name: eventName, ref } = request.body
    const projectId = Number(request.body['project_id'])
    const branch = ref.split('/')[2]
    if (eventName === 'push') {
        const buildId = cuid()
        const applicationFound = await db.getApplicationWebhook({ projectId, branch })
        if (!applicationFound.configHash) {
            const configHash = crypto
                .createHash('sha256')
                .update(
                    JSON.stringify({
                        buildPack: applicationFound.buildPack,
                        port: applicationFound.port,
                        installCommand: applicationFound.installCommand,
                        buildCommand: applicationFound.buildCommand,
                        startCommand: applicationFound.startCommand,
                    })
                )
                .digest('hex')
            await db.prisma.application.updateMany({ where: { branch, projectId }, data: { configHash } })
        }
        await buildQueue.add(buildId, { build_id: buildId, type: 'webhook', ...applicationFound })
        return {
            status: 200,
            body: {
                message: 'Queued. Thank you!'
            }
        }
    }

    return {
        status: 500,
        body: {
            message: 'Not handled event.'
        }
    }
}

// {
//     "object_kind": "push",
//     "event_name": "push",
//     "before": "45b7898888840e55b046e0b01bacd529d010387b",
//     "after": "530cace23f4e13d5cbb516dd0a1ffdbd636689f9",
//     "ref": "refs/heads/master",
//     "checkout_sha": "530cace23f4e13d5cbb516dd0a1ffdbd636689f9",
//     "message": null,
//     "user_id": 2010476,
//     "user_name": "András Bácsai",
//     "user_username": "andrasbacsai",
//     "user_email": "",
//     "user_avatar": "https://secure.gravatar.com/avatar/b70ee596e0c0dcfd09481ddd2c95a66d?s=80&d=identicon",
//     "project_id": 7260661,
//     "project": {
//       "id": 7260661,
//       "name": "coolLabs.io-frontend-v1",
//       "description": "First version of the frontend. Currently not in use.",
//       "web_url": "https://gitlab.com/coollabsio/coolLabs.io-frontend-v1",
//       "avatar_url": null,
//       "git_ssh_url": "git@gitlab.com:coollabsio/coolLabs.io-frontend-v1.git",
//       "git_http_url": "https://gitlab.com/coollabsio/coolLabs.io-frontend-v1.git",
//       "namespace": "coolLabs",
//       "visibility_level": 0,
//       "path_with_namespace": "coollabsio/coolLabs.io-frontend-v1",
//       "default_branch": "master",
//       "ci_config_path": null,
//       "homepage": "https://gitlab.com/coollabsio/coolLabs.io-frontend-v1",
//       "url": "git@gitlab.com:coollabsio/coolLabs.io-frontend-v1.git",
//       "ssh_url": "git@gitlab.com:coollabsio/coolLabs.io-frontend-v1.git",
//       "http_url": "https://gitlab.com/coollabsio/coolLabs.io-frontend-v1.git"
//     },
//     "commits": [
//       {
//         "id": "530cace23f4e13d5cbb516dd0a1ffdbd636689f9",
//         "message": "Delete .gitlab-ci.yml",
//         "title": "Delete .gitlab-ci.yml",
//         "timestamp": "2018-07-16T12:05:34+00:00",
//         "url": "https://gitlab.com/coollabsio/coolLabs.io-frontend-v1/-/commit/530cace23f4e13d5cbb516dd0a1ffdbd636689f9",
//         "author": {
//           "name": "András Bácsai",
//           "email": "andras.bacsai@protonmail.com"
//         },
//         "added": [

//         ],
//         "modified": [

//         ],
//         "removed": [
//           ".gitlab-ci.yml"
//         ]
//       },
//       {
//         "id": "2d150c2dbce111b486dd2717b174ffec7eca43af",
//         "message": "Update .gitlab-ci.yml",
//         "title": "Update .gitlab-ci.yml",
//         "timestamp": "2018-07-15T11:48:28+00:00",
//         "url": "https://gitlab.com/coollabsio/coolLabs.io-frontend-v1/-/commit/2d150c2dbce111b486dd2717b174ffec7eca43af",
//         "author": {
//           "name": "András Bácsai",
//           "email": "andras.bacsai@protonmail.com"
//         },
//         "added": [

//         ],
//         "modified": [
//           ".gitlab-ci.yml"
//         ],
//         "removed": [

//         ]
//       },
//       {
//         "id": "45b7898888840e55b046e0b01bacd529d010387b",
//         "message": "Update .gitlab-ci.yml",
//         "title": "Update .gitlab-ci.yml",
//         "timestamp": "2018-07-15T11:27:52+00:00",
//         "url": "https://gitlab.com/coollabsio/coolLabs.io-frontend-v1/-/commit/45b7898888840e55b046e0b01bacd529d010387b",
//         "author": {
//           "name": "András Bácsai",
//           "email": "andras.bacsai@protonmail.com"
//         },
//         "added": [

//         ],
//         "modified": [
//           ".gitlab-ci.yml"
//         ],
//         "removed": [

//         ]
//       }
//     ],
//     "total_commits_count": 3,
//     "push_options": {
//     },
//     "repository": {
//       "name": "coolLabs.io-frontend-v1",
//       "url": "git@gitlab.com:coollabsio/coolLabs.io-frontend-v1.git",
//       "description": "First version of the frontend. Currently not in use.",
//       "homepage": "https://gitlab.com/coollabsio/coolLabs.io-frontend-v1",
//       "git_http_url": "https://gitlab.com/coollabsio/coolLabs.io-frontend-v1.git",
//       "git_ssh_url": "git@gitlab.com:coollabsio/coolLabs.io-frontend-v1.git",
//       "visibility_level": 0
//     }
//   }