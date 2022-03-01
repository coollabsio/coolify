import * as Bullmq from 'bullmq';
import { default as ProdBullmq, Job, QueueEvents, QueueScheduler } from 'bullmq';
import cuid from 'cuid';
import { dev } from '$app/env';
import { prisma } from '$lib/database';

import builder from './builder';
import logger from './logger';
import cleanup from './cleanup';
import proxy from './proxy';
import ssl from './ssl';
import sslrenewal from './sslrenewal';

import { asyncExecShell, saveBuildLog } from '$lib/common';

let { Queue, Worker } = Bullmq;
let redisHost = 'localhost';

if (!dev) {
	Queue = ProdBullmq.Queue;
	Worker = ProdBullmq.Worker;
	redisHost = 'coolify-redis';
}

const connectionOptions = {
	connection: {
		host: redisHost
	}
};

const cron = async () => {
	new QueueScheduler('proxy', connectionOptions);
	new QueueScheduler('cleanup', connectionOptions);
	new QueueScheduler('ssl', connectionOptions);
	new QueueScheduler('sslRenew', connectionOptions);

	const queue = {
		proxy: new Queue('proxy', { ...connectionOptions }),
		cleanup: new Queue('cleanup', { ...connectionOptions }),
		ssl: new Queue('ssl', { ...connectionOptions }),
		sslRenew: new Queue('sslRenew', { ...connectionOptions })
	};
	await queue.proxy.drain();
	await queue.cleanup.drain();
	await queue.ssl.drain();
	await queue.sslRenew.drain();

	new Worker(
		'proxy',
		async () => {
			await proxy();
		},
		{
			...connectionOptions
		}
	);

	new Worker(
		'ssl',
		async () => {
			await ssl();
		},
		{
			...connectionOptions
		}
	);

	new Worker(
		'cleanup',
		async () => {
			await cleanup();
		},
		{
			...connectionOptions
		}
	);

	new Worker(
		'sslRenew',
		async () => {
			await sslrenewal();
		},
		{
			...connectionOptions
		}
	);

	await queue.proxy.add('proxy', {}, { repeat: { every: 10000 } });
	await queue.ssl.add('ssl', {}, { repeat: { every: 60000 } });
	await queue.cleanup.add('cleanup', {}, { repeat: { every: 600000 } });
	await queue.sslRenew.add('sslRenew', {}, { repeat: { every: 1800000 } });

	const events = {
		proxy: new QueueEvents('proxy', { ...connectionOptions }),
		ssl: new QueueEvents('ssl', { ...connectionOptions })
	};

	events.proxy.on('completed', (data) => {
		// console.log(data)
	});
	events.ssl.on('completed', (data) => {
		// console.log(data)
	});
};
cron().catch((error) => {
	console.log('cron failed to start');
	console.log(error);
});

const buildQueueName = 'build_queue';
const buildQueue = new Queue(buildQueueName, connectionOptions);
const buildWorker = new Worker(buildQueueName, async (job) => await builder(job), {
	concurrency: 2,
	...connectionOptions
});

buildWorker.on('completed', async (job: Bullmq.Job) => {
	try {
		await prisma.build.update({ where: { id: job.data.build_id }, data: { status: 'success' } });
	} catch (err) {
		console.log(err);
	} finally {
		const workdir = `/tmp/build-sources/${job.data.repository}/`;
		await asyncExecShell(`rm -fr ${workdir}`);
	}
	return;
});

buildWorker.on('failed', async (job: Bullmq.Job, failedReason) => {
	try {
		await prisma.build.update({ where: { id: job.data.build_id }, data: { status: 'failed' } });
	} catch (error) {
		console.log(error);
	} finally {
		const workdir = `/tmp/build-sources/${job.data.repository}`;
		await asyncExecShell(`rm -fr ${workdir}`);
	}
	saveBuildLog({
		line: 'Failed to deploy!',
		buildId: job.data.build_id,
		applicationId: job.data.id
	});
	saveBuildLog({
		line: `Reason: ${failedReason.toString()}`,
		buildId: job.data.build_id,
		applicationId: job.data.id
	});
});

const buildLogQueueName = 'log_queue';
const buildLogQueue = new Queue(buildLogQueueName, connectionOptions);
const buildLogWorker = new Worker(buildLogQueueName, async (job) => await logger(job), {
	concurrency: 1,
	...connectionOptions
});

export { buildQueue, buildLogQueue };
