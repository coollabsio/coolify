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
//     "object_kind": "merge_request",
//     "event_type": "merge_request",
//     "user": {
//       "id": 2010476,
//       "name": "András Bácsai",
//       "username": "andrasbacsai",
//       "avatar_url": "https://secure.gravatar.com/avatar/b70ee596e0c0dcfd09481ddd2c95a66d?s=80&d=identicon",
//       "email": "[REDACTED]"
//     },
//     "project": {
//       "id": 16690121,
//       "name": "coolLabs.io",
//       "description": "",
//       "web_url": "https://gitlab.com/coollabsio/production/coollabs.io",
//       "avatar_url": null,
//       "git_ssh_url": "git@gitlab.com:coollabsio/production/coollabs.io.git",
//       "git_http_url": "https://gitlab.com/coollabsio/production/coollabs.io.git",
//       "namespace": "Production Web Applications",
//       "visibility_level": 0,
//       "path_with_namespace": "coollabsio/production/coollabs.io",
//       "default_branch": "master",
//       "ci_config_path": null,
//       "homepage": "https://gitlab.com/coollabsio/production/coollabs.io",
//       "url": "git@gitlab.com:coollabsio/production/coollabs.io.git",
//       "ssh_url": "git@gitlab.com:coollabsio/production/coollabs.io.git",
//       "http_url": "https://gitlab.com/coollabsio/production/coollabs.io.git"
//     },
//     "object_attributes": {
//       "assignee_id": null,
//       "author_id": 2010476,
//       "created_at": "2021-12-17T08:58:50.489Z",
//       "description": "",
//       "head_pipeline_id": null,
//       "id": 131552249,
//       "iid": 1,
//       "last_edited_at": null,
//       "last_edited_by_id": null,
//       "merge_commit_sha": null,
//       "merge_error": null,
//       "merge_params": {
//         "force_remove_source_branch": "1"
//       },
//       "merge_status": "cannot_be_merged",
//       "merge_user_id": null,
//       "merge_when_pipeline_succeeds": false,
//       "milestone_id": null,
//       "source_branch": "next",
//       "source_project_id": 16690121,
//       "state_id": 1,
//       "target_branch": "master",
//       "target_project_id": 16690121,
//       "time_estimate": 0,
//       "title": "Draft: PR",
//       "updated_at": "2021-12-17T08:58:50.489Z",
//       "updated_by_id": null,
//       "url": "https://gitlab.com/coollabsio/production/coollabs.io/-/merge_requests/1",
//       "source": {
//         "id": 16690121,
//         "name": "coolLabs.io",
//         "description": "",
//         "web_url": "https://gitlab.com/coollabsio/production/coollabs.io",
//         "avatar_url": null,
//         "git_ssh_url": "git@gitlab.com:coollabsio/production/coollabs.io.git",
//         "git_http_url": "https://gitlab.com/coollabsio/production/coollabs.io.git",
//         "namespace": "Production Web Applications",
//         "visibility_level": 0,
//         "path_with_namespace": "coollabsio/production/coollabs.io",
//         "default_branch": "master",
//         "ci_config_path": null,
//         "homepage": "https://gitlab.com/coollabsio/production/coollabs.io",
//         "url": "git@gitlab.com:coollabsio/production/coollabs.io.git",
//         "ssh_url": "git@gitlab.com:coollabsio/production/coollabs.io.git",
//         "http_url": "https://gitlab.com/coollabsio/production/coollabs.io.git"
//       },
//       "target": {
//         "id": 16690121,
//         "name": "coolLabs.io",
//         "description": "",
//         "web_url": "https://gitlab.com/coollabsio/production/coollabs.io",
//         "avatar_url": null,
//         "git_ssh_url": "git@gitlab.com:coollabsio/production/coollabs.io.git",
//         "git_http_url": "https://gitlab.com/coollabsio/production/coollabs.io.git",
//         "namespace": "Production Web Applications",
//         "visibility_level": 0,
//         "path_with_namespace": "coollabsio/production/coollabs.io",
//         "default_branch": "master",
//         "ci_config_path": null,
//         "homepage": "https://gitlab.com/coollabsio/production/coollabs.io",
//         "url": "git@gitlab.com:coollabsio/production/coollabs.io.git",
//         "ssh_url": "git@gitlab.com:coollabsio/production/coollabs.io.git",
//         "http_url": "https://gitlab.com/coollabsio/production/coollabs.io.git"
//       },
//       "last_commit": {
//         "id": "74b60f621d7ea57be6c1bd95cc86d982001e464c",
//         "message": "Ton updates\n",
//         "title": "Ton updates",
//         "timestamp": "2019-12-27T15:15:08+01:00",
//         "url": "https://gitlab.com/coollabsio/production/coollabs.io/-/commit/74b60f621d7ea57be6c1bd95cc86d982001e464c",
//         "author": {
//           "name": "andrasbacsai",
//           "email": "andras.bacsai@gmail.com"
//         }
//       },
//       "work_in_progress": true,
//       "total_time_spent": 0,
//       "time_change": 0,
//       "human_total_time_spent": null,
//       "human_time_change": null,
//       "human_time_estimate": null,
//       "assignee_ids": [],
//       "state": "opened",
//       "blocking_discussions_resolved": true
//     },
//     "labels": [],
//     "changes": {},
//     "repository": {
//       "name": "coolLabs.io",
//       "url": "git@gitlab.com:coollabsio/production/coollabs.io.git",
//       "description": "",
//       "homepage": "https://gitlab.com/coollabsio/production/coollabs.io"
//     }
//   }