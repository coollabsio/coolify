import { goto } from '$app/navigation';
import { errorNotification } from '$lib/common';
import { trpc } from '$lib/store';

export async function saveForm(id, application, baseDatabaseBranch, dockerComposeConfiguration) {
	let {
		name,
		buildPack,
		fqdn,
		port,
		exposePort,
		installCommand,
		buildCommand,
		startCommand,
		baseDirectory,
		publishDirectory,
		pythonWSGI,
		pythonModule,
		pythonVariable,
		dockerFileLocation,
		denoMainFile,
		denoOptions,
		gitCommitHash,
		baseImage,
		baseBuildImage,
		deploymentType,
		dockerComposeFile,
		dockerComposeFileLocation,
		simpleDockerfile,
		dockerRegistryImageName
	} = application;
	return await trpc.applications.save.mutate({
		id,
		name,
		buildPack,
		fqdn,
		port,
		exposePort,
		installCommand,
		buildCommand,
		startCommand,
		baseDirectory,
		publishDirectory,
		pythonWSGI,
		pythonModule,
		pythonVariable,
		dockerFileLocation,
		denoMainFile,
		denoOptions,
		gitCommitHash,
		baseImage,
		baseBuildImage,
		deploymentType,
		dockerComposeFile,
		dockerComposeFileLocation,
		simpleDockerfile,
		dockerRegistryImageName,
		baseDatabaseBranch,
		dockerComposeConfiguration: JSON.stringify(dockerComposeConfiguration)
	});
}
