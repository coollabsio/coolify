import { saveBuildLog } from '$lib/common'
import * as Bullmq from 'bullmq'
import { default as ProdBullmq } from 'bullmq'
import cuid from 'cuid'
import { dev } from '$app/env';
import { prisma } from '$lib/database';
import builder from './builder';
import letsencrypt from './letsencrypt';
import logger from './logger';
let { Queue, Worker } = Bullmq;
let redisHost = 'localhost';

if (!dev) {
  Queue = ProdBullmq.Queue;
  Worker = ProdBullmq.Worker;
  redisHost = 'coolify-redis'
} 

const buildQueueName = dev ? cuid() : 'build_queue'
const buildQueue = new Queue(buildQueueName, {
  connection: {
    host: redisHost
  }
})
const buildWorker = new Worker(buildQueueName, async (job) => await builder(job), {
  concurrency: 2,
  connection: {
    host: redisHost
  }
})

buildWorker.on('completed', async (job: Bullmq.Job) => {
  try {
    await prisma.build.update({ where: { id: job.data.build_id }, data: { status: 'success' } })
  } catch (err) {
    console.log(err)
  }
})

buildWorker.on('failed', async (job: Bullmq.Job, failedReason: string) => {
  console.log(failedReason)
  try {
    await prisma.build.update({ where: { id: job.data.build_id }, data: { status: 'failed' } })
  } catch (error) {
    console.log(error)
  }
  saveBuildLog({ line: 'Failed build!', buildId: job.data.build_id, applicationId: job.data.id })
  saveBuildLog({ line: `Reason: ${failedReason.toString()}`, buildId: job.data.build_id, applicationId: job.data.id })
})

const letsEncryptQueueName = dev ? cuid() : 'letsencrypt_queue'
const letsEncryptQueue = new Queue(letsEncryptQueueName, {
  connection: {
    host: redisHost
  }
})

const letsEncryptWorker = new Worker(letsEncryptQueueName, async (job) => await letsencrypt(job), {
  concurrency: 1,
  connection: {
    host: redisHost
  }
})
letsEncryptWorker.on('completed', async () => {
  // TODO: Save letsencrypt logs as build logs!
  console.log('Lets Encrypt job completed')
})

letsEncryptWorker.on('failed', async (failedReason: string) => {
  console.log('Lets Encrypt job failed')
  console.log(failedReason)
})


const buildLogQueueName = dev ? cuid() : 'log_queue'
const buildLogQueue = new Queue(buildLogQueueName, {
  connection: {
    host: redisHost
  }
})
const buildLogWorker = new Worker(buildLogQueueName, async (job) => await logger(job), {
  concurrency: 1,
  connection: {
    host: redisHost
  }
})


export { buildQueue, letsEncryptQueue, buildLogQueue }
