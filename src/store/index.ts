import type { Application, Dashboard, DateTimeFormatOptions, GithubInstallations } from 'src/global';
import { writable, derived, readable, Writable } from 'svelte/store';

export const dashboard = writable<Dashboard>({
	applications: {
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

export const githubRepositories = writable([])
export const githubInstallations = writable<GithubInstallations>([])
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
		workdir: null
	},
	build: {
		pack: 'static',
		directory: null,
		command: {
			build: null,
			installation: null
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
})


export const initConf = writable({})

export const initialApplication = {
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
	  workdir: null
	},
	build: {
	  pack: 'static',
	  directory: null,
	  command: {
		build: null,
		installation: null
	  },
	  container: {
		name: null,
		tag: null
	  }
	},
	publish: {
	  directory: null,
	  domain: null,
	  path: '/',
	  port: null,
	  secrets: []
	}
  }