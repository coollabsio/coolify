import type {
	Application,
	Dashboard,
	Database,
	DateTimeFormatOptions,
	GithubInstallations
} from 'src/global';
import { writable } from 'svelte/store';
export const settings = writable({
	clientId: null
});
export const dashboard = writable<Dashboard>({
	databases: {
		deployed: []
	},
	applications: {
		deployed: []
	},
	services: {
		deployed: []
	}
});
export const dateOptions: DateTimeFormatOptions = {
	year: 'numeric',
	month: 'short',
	day: '2-digit',
	hour: 'numeric',
	minute: 'numeric',
	second: 'numeric',
	hour12: false
};

export const githubRepositories = writable([]);
export const githubInstallations = writable<GithubInstallations>([]);
export const application = writable<Application>({
	github: {
		installation: {
			id: null
		},
		app: {
			id: null
		}
	},
	repository: {
		id: null,
		organization: 'new',
		name: 'start',
		branch: null
	},
	general: {
		deployId: null,
		nickname: null,
		workdir: null,
		isPreviewDeploymentEnabled: false,
		pullRequest: 0
	},
	build: {
		pack: 'static',
		directory: null,
		command: {
			build: null,
			installation: null,
			start: null,
			python: {
				module: null,
				instance: null
			}
		},
		container: {
			name: null,
			tag: null,
			baseSHA: null
		}
	},
	publish: {
		directory: null,
		domain: null,
		path: '/',
		port: null,
		secrets: []
	}
});
export const prApplication = writable([]);

export const initConf = writable({});

export const initialApplication: Application = {
	github: {
		installation: {
			id: null
		},
		app: {
			id: null
		}
	},
	repository: {
		id: null,
		organization: null,
		name: null,
		branch: null
	},
	general: {
		deployId: null,
		nickname: null,
		workdir: null,
		isPreviewDeploymentEnabled: false,
		pullRequest: 0
	},
	build: {
		pack: 'static',
		directory: null,
		command: {
			build: null,
			installation: null,
			start: null,
			python: {
				module: null,
				instance: null
			}
		},
		container: {
			name: null,
			tag: null,
			baseSHA: null
		}
	},
	publish: {
		directory: null,
		domain: null,
		path: '/',
		port: null,
		secrets: []
	}
};
export const initialDatabase: Database = {
	config: {
		general: {
			workdir: null,
			deployId: null,
			nickname: null,
			type: null
		},
		database: {
			username: null,
			passwords: [],
			defaultDatabaseName: null
		},
		deploy: {
			name: null
		}
	},
	envs: {}
};
export const database = writable<Database>({
	config: {},
	envs: []
});
export const newService = writable({
	email: null,
	userName: 'admin',
	userPassword: null,
	userPasswordAgain: null,
	baseURL: null
});
export const initialNewService = {
	email: null,
	userName: 'admin',
	userPassword: null,
	userPasswordAgain: null,
	baseURL: null
};
export const newWordpressService = writable({
	baseURL: null,
	remoteDB: false,
	database: {
		host: null,
		name: 'wordpress',
		user: null,
		password: null,
		tablePrefix: 'wordpress'
	},
	wordpressExtraConfiguration: null
});

export const isPullRequestPermissionsGranted = writable(false);
