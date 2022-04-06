export type ComposeFile = {
	version: ComposerFileVersion;
	services: Record<string, ComposeFileService>;
	networks: Record<string, ComposeFileNetwork>;
	volumes?: Record<string, ComposeFileVolume>;
};

export type ComposeFileService = {
	container_name: string;
	image?: string;
	networks: string[];
	environment?: Record<string, unknown>;
	volumes?: string[];
	ulimits?: unknown;
	labels?: string[];
	env_file?: string[];
	extra_hosts?: string[];
	restart: ComposeFileRestartOption;
	depends_on?: string[];
	command?: string;
	build?: {
		context: string;
		dockerfile: string;
		args?: Record<string, unknown>;
	};
};

export type ComposerFileVersion =
	| '3.8'
	| '3.7'
	| '3.6'
	| '3.5'
	| '3.4'
	| '3.3'
	| '3.2'
	| '3.1'
	| '3.0'
	| '2.4'
	| '2.3'
	| '2.2'
	| '2.1'
	| '2.0';

export type ComposeFileRestartOption = 'no' | 'always' | 'on-failure' | 'unless-stopped';

export type ComposeFileNetwork = {
	external: boolean;
};

export type ComposeFileVolume = {
	external?: boolean;
	name?: string;
};
