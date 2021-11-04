
import crypto from 'crypto'
import * as buildpacks from '../buildPacks'
import * as importers from '../importers'
import { dockerInstance } from '../docker'
import { asyncExecShell, saveBuildLog } from '../common'
import { completeTransaction, getNextTransactionId, haproxyInstance } from '../haproxy'
import * as db from '$lib/database'

export default async function (job) {
  /*
    Edge cases:
    1 - Change build pack and redeploy, what should happen?
  */
  let { id: applicationId, repository, branch, buildPack, destinationDocker, gitSource, build_id: buildId, configHash, port, installCommand, buildCommand, startCommand, domain, oldDomain, baseDirectory, publishDirectory } = job.data

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
      githubAppId: gitSource.githubApp.id,
      status: 'running',
    }
  })

  const workdir = `/tmp/build-sources/${repository}/${build.id}`
  await asyncExecShell(`mkdir -p ${workdir}`)
  console.log(workdir)
  // TODO: Separate logic
  if (buildPack === 'node') {
    if (!port) port = 3000
    if (!installCommand) installCommand = 'yarn install'
    if (!startCommand) startCommand = 'yarn start'
  }
  if (buildPack === 'static') {
    port = 80
  }
  const commit = await importers[gitSource.type]({ applicationId, workdir, githubAppId: gitSource.githubApp.id, repository, branch, buildId: build.id })

  await db.prisma.build.update({ where: { id: build.id }, data: { commit } })

  const currentHash = crypto.createHash('sha256').update(JSON.stringify({ buildPack, port, installCommand, buildCommand, startCommand })).digest('hex')
  if (configHash !== currentHash) {
    await db.prisma.application.update({ where: { id: applicationId }, data: { configHash: currentHash } })
    deployNeeded = true
    saveBuildLog({ line: 'Configuration changed, redeploying.', buildId, applicationId })
  } else {
    deployNeeded = false
  }

  // TODO: This needs to be corrected.
  const image = await docker.engine.getImage(`${applicationId}:${commit.slice(0, 7)}`)

  let imageFound = false
  try {
    await image.inspect()
    imageFound = false
  } catch (error) {
    //
  }
  // TODO: Should check if it's running!
  if (!imageFound || deployNeeded) {
    await buildpacks[buildPack]({ applicationId, commit, workdir, docker, buildId: build.id, port, installCommand, buildCommand, startCommand, baseDirectory, publishDirectory })
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
        await asyncExecShell(`docker stop -t 0 ${applicationId}`)
        await asyncExecShell(`docker rm ${applicationId}`)
      } catch (error) {
        //
      } finally {
        saveBuildLog({ line: 'Remove old deployments.', buildId, applicationId })
      }

      // TODO: Must be localhost
      if (destinationDocker.engine === '/var/run/docker.sock') {
        saveBuildLog({ line: 'Deploying.', buildId, applicationId })
        const { stderr } = await asyncExecShell(`docker run --name ${applicationId} --network ${docker.network} --restart always -d ${applicationId}:${commit.slice(0, 7)}`)
        if (stderr) console.log(stderr)
        saveBuildLog({ line: 'Deployment successful!', buildId, applicationId })
      }
      // TODO: Implement remote docker engine

    } else if (destinationSwarm) {
      // Deploy to swarm
    } else if (kubernetes) {
      // Deploy to k8s
    }
  }
  // TODO: Separate logic
  const haproxy = haproxyInstance()

  try {
    const transactionId = await getNextTransactionId()
    try {
      const backendFound = await haproxy.get(`v2/services/haproxy/configuration/backends/${domain}`).json()
      if (backendFound) {
        await haproxy.delete(`v2/services/haproxy/configuration/backends/${domain}`, {
          searchParams: {
            transaction_id: transactionId
          },
        }).json()
        saveBuildLog({ line: 'HAPROXY - Old backend deleted.', buildId, applicationId })
      }

    } catch (error) {
      // Backend not found, no worries, it means it's not defined yet
    }
    try {
      if (oldDomain) {
        await haproxy.delete(`v2/services/haproxy/configuration/backends/${oldDomain}`, {
          searchParams: {
            transaction_id: transactionId
          },
        }).json()
        await db.prisma.application.update({ where: { id: applicationId }, data: { oldDomain: null } })
        saveBuildLog({ line: 'HAPROXY - Old backend deleted with different domain.', buildId, applicationId })
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

    saveBuildLog({ line: 'HAPROXY - New backend defined.', buildId, applicationId })
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
    saveBuildLog({ line: 'HAPROXY - New servers defined.', buildId, applicationId })
    await completeTransaction(transactionId)
    saveBuildLog({ line: 'HAPROXY - Transaction done.', buildId, applicationId })
  } catch (error) {
    console.log(error)
    throw new Error(error)
  }


  // await asyncExecShell(`rm -fr ${workdir}`)
}
