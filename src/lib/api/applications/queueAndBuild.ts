import Deployment from '$models/Deployment';
import dayjs from 'dayjs';
import buildContainer from './buildContainer';
import { updateServiceLabels } from './configuration';
import copyFiles from './copyFiles';
import deploy from './deploy';
import { saveAppLog } from './logging';

export default async function (configuration, imageChanged) {
	const { id, organization, name, branch } = configuration.repository;
	const { domain } = configuration.publish;
	const { deployId } = configuration.general;
	try {
		await saveAppLog(`${dayjs().format('YYYY-MM-DD HH:mm:ss.SSS')} Queued.`, configuration);
		await copyFiles(configuration);
		await buildContainer(configuration);
		await deploy(configuration, imageChanged);
		await Deployment.findOneAndUpdate(
			{ repoId: id, branch, deployId, organization, name, domain },
			{ repoId: id, branch, deployId, organization, name, domain, progress: 'done' }
		);

		await updateServiceLabels(configuration);
	} catch (error) {
		await Deployment.findOneAndUpdate(
			{ repoId: id, branch, deployId, organization, name, domain },
			{ repoId: id, branch, deployId, organization, name, domain, progress: 'failed' }
		);
	}
}
