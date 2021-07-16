import Deployment from '$models/Deployment';
import dayjs from 'dayjs';
import buildContainer from './buildContainer';
import { purgeImagesContainers } from './cleanup';
import { updateServiceLabels } from './configuration';
import copyFiles from './copyFiles';
import deploy from './deploy';
import { saveAppLog } from './logging';

export default async function (configuration, nextStep) {
	const { id, organization, name, branch } = configuration.repository;
	const { domain } = configuration.publish;
	const { deployId } = configuration.general;
	try {
		await saveAppLog(`### Successfully queued.`, configuration);
		await copyFiles(configuration);
		await buildContainer(configuration);
		await deploy(configuration, nextStep);
		await Deployment.findOneAndUpdate(
			{ repoId: id, branch, deployId, organization, name, domain },
			{ repoId: id, branch, deployId, organization, name, domain, progress: 'done' }
		);

		await updateServiceLabels(configuration);
		await purgeImagesContainers(configuration);
	} catch (error) {
		await Deployment.findOneAndUpdate(
			{ repoId: id, branch, deployId, organization, name, domain },
			{ repoId: id, branch, deployId, organization, name, domain, progress: 'failed' }
		);
	}
}
