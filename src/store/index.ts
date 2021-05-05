import type { Application, Dashboard, DateTimeFormatOptions } from 'src/global';
import { writable, derived, readable, Writable } from 'svelte/store';



export const dashboard = writable<Dashboard>({});
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