/// <reference types="@sveltejs/kit" />
/// <reference types="svelte" />
/// <reference types="vite/client" />

export type DateTimeFormatOptions = {
	localeMatcher?: 'lookup' | 'best fit';
	weekday?: 'long' | 'short' | 'narrow';
	era?: 'long' | 'short' | 'narrow';
	year?: 'numeric' | '2-digit';
	month?: 'numeric' | '2-digit' | 'long' | 'short' | 'narrow';
	day?: 'numeric' | '2-digit';
	hour?: 'numeric' | '2-digit';
	minute?: 'numeric' | '2-digit';
	second?: 'numeric' | '2-digit';
	timeZoneName?: 'long' | 'short';
	formatMatcher?: 'basic' | 'best fit';
	hour12?: boolean;
	timeZone?: string;
};
export type Application = {
	github: {
		installation: {
			id: number;
		};
		app: {
			id: number;
		};
	};
	repository: {
		id: number;
		organization: string;
		name: string;
		branch: string;
	};
	general: {
		deployId: string;
		nickname: string;
		workdir: string;
		isPreviewDeploymentEnabled: boolean;
		pullRequest: number;
	};
	build: {
		pack: string;
		directory: string;
		command: {
			build: string | null;
			installation: string;
			start: string;
			python: {
				module?: string;
				instance?: string;
			};
		};
		container: {
			name: string;
			tag: string;
			baseSHA: string;
		};
	};
	publish: {
		directory: string;
		domain: string;
		path: string;
		port: number;
		secrets: Array<Record<string, unknown>>;
	};
};
export type Database = {
	config:
		| {
				general: {
					deployId: string;
					nickname: string;
					workdir: string;
					type: string;
				};
				database: {
					usernames: Array;
					passwords: Array;
					defaultDatabaseName: string;
				};
				deploy: {
					name: string;
				};
		  }
		| Record<string, unknown>;
	envs: Array;
};
export type Dashboard = {
	databases: {
		deployed:
			| [
					{
						configuration: Database;
					}
			  ]
			| [];
	};
	services: {
		deployed:
			| [
					{
						configuration: any;
					}
			  ]
			| [];
	};
	applications: {
		deployed:
			| [
					{
						configuration: Application;
						UpdatedAt: any;
					}
			  ]
			| [];
	};
};
export type GithubInstallations = {
	id: number;
	app_id: number;
};
