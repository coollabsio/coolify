
import crypto from 'crypto'
import * as buildpacks from '../buildPacks'
import * as importers from '../importers'
import { dockerInstance } from '../docker'
import { asyncExecShell, saveBuildLog } from '../common'
import { completeTransaction, getNextTransactionId, haproxyInstance } from '../haproxy'
import * as db from '$lib/database'
import { decrypt } from '$lib/crypto'

export default async function (job) {
  /*
    Edge cases:
    1 - Change build pack and redeploy, what should happen?
  */
  let { id: applicationId, repository, branch, buildPack, name, destinationDocker, gitSource, build_id: buildId, configHash, port, installCommand, buildCommand, startCommand, domain, oldDomain, baseDirectory, publishDirectory, projectId, debugLogs, secrets, type, pullmergeRequestId = null, sourceBranch = null, mergepullRequestDeployments } = job.data

  let imageId = applicationId
  // Merge/pull requests, we need to get the source branch and set subdomain
  if (pullmergeRequestId) {
    branch = sourceBranch
    domain = `${pullmergeRequestId}.${domain}`
    imageId = `${applicationId}-${pullmergeRequestId}`
  }

  const destinationSwarm = null
  const kubernetes = null

  let deployNeeded = true

  const docker = dockerInstance({ destinationDocker })

  const build = await db.prisma.build.create({
    data: {
      id: buildId,
      applicationId,
      destinationDockerId: destinationDocker.id,
      gitSourceId: gitSource.id,
      githubAppId: gitSource.githubApp?.id,
      gitlabAppId: gitSource.gitlabApp?.id,
      status: 'running',
      type,
    }
  })

  const workdir = `/tmp/build-sources/${repository}/${build.id}`
  const repodir = `/tmp/build-sources/${repository}/`
  await asyncExecShell(`mkdir -p ${workdir}`)

  // TODO: Separate logic
  if (buildPack === 'node') {
    if (!port) port = 3000
    if (!installCommand) installCommand = 'yarn install'
    if (!startCommand) startCommand = 'yarn start'
  }
  if (buildPack === 'static') {
    port = 80
  }
  let commit = await importers[gitSource.type]({
    applicationId,
    debugLogs,
    workdir,
    repodir,
    githubAppId: gitSource.githubApp?.id,
    gitlabAppId: gitSource.gitlabApp?.id,
    repository,
    branch,
    buildId: build.id,
    apiUrl: gitSource.apiUrl,
    projectId,
    deployKeyId: gitSource.gitlabApp?.deployKeyId || null,
    privateSshKey: decrypt(gitSource.gitlabApp?.privateSshKey) || null
  })

  let tag = commit.slice(0, 7)
  if (pullmergeRequestId) {
    tag = `${commit.slice(0, 7)}-${pullmergeRequestId}`
  }

  try {
    await db.prisma.build.update({ where: { id: build.id }, data: { commit } })
  } catch (err) {
    console.log(err)
  }
  if (!pullmergeRequestId) {
    const currentHash = crypto.createHash('sha256').update(JSON.stringify({ buildPack, port, installCommand, buildCommand, startCommand, secrets, branch, repository, domain })).digest('hex')
    if (configHash !== currentHash) {
      await db.prisma.application.update({ where: { id: applicationId }, data: { configHash: currentHash } })
      deployNeeded = true
      saveBuildLog({ line: '[COOLIFY] - Configuration changed.', buildId, applicationId })
    } else {
      deployNeeded = false
    }
  } else {
    deployNeeded = true
  }

  // TODO: This needs to be corrected.
  const image = await docker.engine.getImage(`${applicationId}:${tag}`)

  let imageFound = false
  try {
    await image.inspect()
    imageFound = false
  } catch (error) {
    //
  }
  // TODO: Should check if it's running!
  if (!imageFound || deployNeeded) {
    await buildpacks[buildPack]({ buildId: build.id, applicationId, domain, name, type, pullmergeRequestId, buildPack, repository, branch, projectId, publishDirectory, debugLogs, commit, tag, workdir, docker, port, installCommand, buildCommand, startCommand, baseDirectory, secrets })
    deployNeeded = true
  } else {
    deployNeeded = false
    saveBuildLog({ line: 'Nothing changed.', buildId, applicationId })
  }

  // TODO: Separate logic
  if (deployNeeded) {
    if (destinationDocker) {
      // Deploy to docker
      try {
        await asyncExecShell(`docker stop -t 0 ${imageId}`)
        await asyncExecShell(`docker rm ${imageId}`)
      } catch (error) {
        //
      } finally {
        saveBuildLog({ line: '[COOLIFY] - Removing old deployments.', buildId, applicationId })
      }

      // TODO: Must be localhost
      if (destinationDocker.engine === '/var/run/docker.sock') {
        saveBuildLog({ line: '[COOLIFY] - Deployment started.', buildId, applicationId })
        const envs = []
        if (secrets.length > 0) {
          secrets.forEach(secret => {
            if (!secret.isBuildSecret) {
              envs.push(`--env ${secret.name}=${secret.value}`)
            }
          })
        }
        const { stderr } = await asyncExecShell(`docker run ${envs.join()} --name ${imageId} --network ${docker.network} --restart always -d ${applicationId}:${tag}`)
        if (stderr) console.log(stderr)
        saveBuildLog({ line: '[COOLIFY] - Deployment successful!', buildId, applicationId })
      }
      // TODO: Implement remote docker engine

    } else if (destinationSwarm) {
      // Deploy to swarm
    } else if (kubernetes) {
      // Deploy to k8s
    }
  }
  // destinationDocker.isCoolifyProxyUsed
  // const isCoolifyProxyUsed = await getSetting({ name: 'isCoolifyProxyUsed' })

  // TODO: Separate logic
  if (destinationDocker.isCoolifyProxyUsed) {
    const haproxy = haproxyInstance()

    try {
      saveBuildLog({ line: '[PROXY] - Configuring proxy.', buildId, applicationId })
      const transactionId = await getNextTransactionId()
      try {
        const backendFound = await haproxy.get(`v2/services/haproxy/configuration/backends/${domain}`).json()
        if (backendFound) {
          await haproxy.delete(`v2/services/haproxy/configuration/backends/${domain}`, {
            searchParams: {
              transaction_id: transactionId
            },
          }).json()

        }

      } catch (error) {
        // Backend not found, no worries, it means it's not defined yet
      }
      try {
        if (oldDomain) {
          // TODO: PRMR builds should be deleted or reconfigured as well??
          await haproxy.delete(`v2/services/haproxy/configuration/backends/${oldDomain}`, {
            searchParams: {
              transaction_id: transactionId
            },
          }).json()
          await db.prisma.application.update({ where: { id: applicationId }, data: { oldDomain: null } })
        }
      } catch (error) {
        // Backend not found, no worries, it means it's not defined yet
      }
      await haproxy.post('v2/services/haproxy/configuration/backends', {
        searchParams: {
          transaction_id: transactionId
        },
        json: {
          "init-addr": "last,libc,none",
          "forwardfor": { "enabled": "enabled" },
          "name": domain
        }
      })

      await haproxy.post('v2/services/haproxy/configuration/servers', {
        searchParams: {
          transaction_id: transactionId,
          backend: domain
        },
        json: {
          "address": applicationId,
          "check": "enabled",
          "name": applicationId,
          "port": port
        }
      })
      await completeTransaction(transactionId)
      saveBuildLog({ line: '[PROXY] - Configured.', buildId, applicationId })
    } catch (error) {
      console.log(error.response.body)
      throw new Error(error)
    } finally {
      await asyncExecShell(`rm -fr ${workdir}`)
    }
  } else {
    saveBuildLog({ line: '[COOLIFY] - Custom proxy is configured. Nothing else to do.', buildId, applicationId })
  }
  await asyncExecShell(`rm -fr ${workdir}`)
}
