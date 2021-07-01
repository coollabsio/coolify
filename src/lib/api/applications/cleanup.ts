import { docker } from '$lib/api/docker';
import Deployment from '$models/Deployment';
import { execShellAsync } from '../common';
import crypto from 'crypto';
export async function deleteSameDeployments(configuration, originalDomain = null) {
	await (
		await docker.engine.listServices()
	)
		.filter((r) => r.Spec.Labels.managedBy === 'coolify' && r.Spec.Labels.type === 'application')
		.map(async (s) => {
			const running = JSON.parse(s.Spec.Labels.configuration);
			if (
				running.repository.id === configuration.repository.id &&
				running.repository.branch === configuration.repository.branch &&
				running.publish.domain === originalDomain || configuration.publish.domain
			) {
				await execShellAsync(`docker stack rm ${s.Spec.Labels['com.docker.stack.namespace']}`);
			}
		});
}

export async function cleanupStuckedDeploymentsInDB() {
	// Cleanup stucked deployments.
	await Deployment.updateMany(
		{ progress: { $in: ['queued', 'inprogress'] } },
		{ progress: 'failed' }
	);
}
export async function purgeImagesContainers(configuration, deleteAll = false, originalDomain) {
	const shaBase = JSON.stringify({ repository: configuration.repository, domain: originalDomain });
	const sha256 = crypto.createHash('sha256').update(shaBase).digest('hex');
	const name = sha256.slice(0, 15)
	const { tag } = configuration.build.container;
	if (originalDomain !== configuration.publish.domain) {
		deleteAll = true
	}
	try {
		await execShellAsync('docker container prune -f');
	} catch (error) {
		//
	}
	try {
		if (deleteAll) {
			const IDsToDelete = (
				await execShellAsync(
					`docker images ls --filter=reference='${name}' --format '{{json .ID }}'`
				)
			)
				.trim()
				.replace(/"/g, '')
				.split('\n');
			if (IDsToDelete.length > 0) await execShellAsync(`docker rmi -f ${IDsToDelete.join(' ')}`);
		} else {
			const IDsToDelete = (
				await execShellAsync(
					`docker images ls --filter=reference='${name}' --filter=before='${name}:${tag}' --format '{{json .ID }}'`
				)
			)
				.trim()
				.replace(/"/g, '')
				.split('\n');
			if (IDsToDelete.length > 1) await execShellAsync(`docker rmi -f ${IDsToDelete.join(' ')}`);
		}
	} catch (error) {
		console.log(error);
	}
	try {
		await execShellAsync('docker image prune -f');
	} catch (error) {
		//
	}
}
