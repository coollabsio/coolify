import type { DestinationDocker, GithubApp, GitlabApp, GitSource, Secret } from '@prisma/client';

export type BuilderJob = {
	build_id: string;
	type: BuildType;
	id: string;
	name: string;
	fqdn: string;
	repository: string;
	configHash: unknown;
	branch: string;
	buildPack: BuildPackName;
	projectId: number;
	port: number;
	installCommand: string;
	buildCommand?: string;
	startCommand?: string;
	baseDirectory: string;
	publishDirectory: string;
	phpModules: string;
	pythonWSGI: string;
	pythonModule: string;
	pythonVariable: string;
	createdAt: string;
	updatedAt: string;
	destinationDockerId: string;
	destinationDocker: DestinationDocker;
	gitSource: GitSource & { githubApp?: GithubApp; gitlabApp?: GitlabApp };
	settings: BuilderJobSettings;
	secrets: Secret[];
	persistentStorage: { path: string }[];
	pullmergeRequestId?: unknown;
	sourceBranch?: string;
};

// TODO: Add the other build types
export type BuildType = 'manual';

// TODO: Add the other buildpack names
export type BuildPackName = 'node' | 'docker';

export type BuilderJobSettings = {
	id: string;
	applicationId: string;
	dualCerts: boolean;
	debug: boolean;
	previews: boolean;
	autodeploy: boolean;
	createdAt: string;
	updatedAt: string;
};
