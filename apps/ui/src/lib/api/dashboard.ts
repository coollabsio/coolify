
export let applications: any = [];
export let databases: any = [];
export let services: any = [];
export let gitSources: any = [];
export let destinations: any = []
export let filtered: any = setInitials();

import {get as getStore} from 'svelte/store'
import { search, resources } from '$lib/store';

import { asyncSleep, errorNotification, getRndInteger } from '$lib/common';

export function setInitials(onlyOthers: boolean = false) {
		return {
			applications:
				!onlyOthers &&
				applications.filter(
					(application: any) =>
						application?.teams.length > 0 && application.teams[0].id === $appSession.teamId
				),
			otherApplications: applications.filter(
				(application: any) =>
					application?.teams.length > 0 && application.teams[0].id !== $appSession.teamId
			),
			databases:
				!onlyOthers &&
				databases.filter(
					(database: any) =>
						database?.teams.length > 0 && database.teams[0].id === $appSession.teamId
				),
			otherDatabases: databases.filter(
				(database: any) => database?.teams.length > 0 && database.teams[0].id !== $appSession.teamId
			),
			services:
				!onlyOthers &&
				services.filter(
					(service: any) => service?.teams.length > 0 && service.teams[0].id === $appSession.teamId
				),
			otherServices: services.filter(
				(service: any) => service?.teams.length > 0 && service.teams[0].id !== $appSession.teamId
			),
			gitSources:
				!onlyOthers &&
				gitSources.filter(
					(gitSource: any) =>
						gitSource?.teams.length > 0 && gitSource.teams[0].id === $appSession.teamId
				),
			otherGitSources: gitSources.filter(
				(gitSource: any) =>
					gitSource?.teams.length > 0 && gitSource.teams[0].id !== $appSession.teamId
			),
			destinations:
				!onlyOthers &&
				destinations.filter(
					(destination: any) =>
						destination?.teams.length > 0 && destination.teams[0].id === $appSession.teamId
				),
			otherDestinations: destinations.filter(
				(destination: any) =>
					destination?.teams.length > 0 && destination.teams[0].id !== $appSession.teamId
			)
		};
	}
export function clearFiltered() {
		filtered.applications = [];
		filtered.otherApplications = [];
		filtered.databases = [];
		filtered.otherDatabases = [];
		filtered.services = [];
		filtered.otherServices = [];
		filtered.gitSources = [];
		filtered.otherGitSources = [];
		filtered.destinations = [];
		filtered.otherDestinations = [];
	}

export function filterState(state: string) {
		clearFiltered();
		filtered.applications = applications.filter((application: any) => {
			if (status[application.id] === state && application.teams[0].id === $appSession.teamId)
				return application;
		});
		filtered.otherApplications = applications.filter((application: any) => {
			if (status[application.id] === state && application.teams[0].id !== $appSession.teamId)
				return application;
		});
		filtered.databases = databases.filter((database: any) => {
			if (status[database.id] === state && database.teams[0].id === $appSession.teamId)
				return database;
		});
		filtered.otherDatabases = databases.filter((database: any) => {
			if (status[database.id] === state && database.teams[0].id !== $appSession.teamId)
				return database;
		});
		filtered.services = services.filter((service: any) => {
			if (status[service.id] === state && service.teams[0].id === $appSession.teamId)
				return service;
		});
		filtered.otherServices = services.filter((service: any) => {
			if (status[service.id] === state && service.teams[0].id !== $appSession.teamId)
				return service;
		});
	}
export function filterSpecific(type: any) {
		clearFiltered();
		const otherType = 'other' + type[0].toUpperCase() + type.substring(1);
		filtered[type] = eval(type).filter(
			(resource: any) => resource.teams[0].id === $appSession.teamId
		);
		filtered[otherType] = eval(type).filter(
			(resource: any) => resource.teams[0].id !== $appSession.teamId
		);
	}
export function applicationFilters(application: any) {
		return (
			(application.id && application.id.toLowerCase().includes(getStore(search).toLowerCase())) ||
			(application.name && application.name.toLowerCase().includes(getStore(search).toLowerCase())) ||
			(application.fqdn && application.fqdn.toLowerCase().includes(getStore(search).toLowerCase())) ||
			(application.dockerComposeConfiguration &&
				application.dockerComposeConfiguration.toLowerCase().includes(getStore(search).toLowerCase())) ||
			(application.repository &&
				application.repository.toLowerCase().includes(getStore(search).toLowerCase())) ||
			(application.buildpack &&
				application.buildpack.toLowerCase().includes(getStore(search).toLowerCase())) ||
			(application.branch && application.branch.toLowerCase().includes(getStore(search).toLowerCase())) ||
			(application.destinationDockerId &&
				application.destinationDocker.name.toLowerCase().includes(getStore(search).toLowerCase())) ||
			('bot'.includes(getStore(search)) && application.settings.isBot)
		);
	}
export function databaseFilters(database: any) {
		return (
			(database.id && database.id.toLowerCase().includes(getStore(search).toLowerCase())) ||
			(database.name && database.name.toLowerCase().includes(getStore(search).toLowerCase())) ||
			(database.type && database.type.toLowerCase().includes(getStore(search).toLowerCase())) ||
			(database.version && database.version.toLowerCase().includes(getStore(search).toLowerCase())) ||
			(database.destinationDockerId &&
				database.destinationDocker.name.toLowerCase().includes(getStore(search).toLowerCase()))
		);
	}
export function serviceFilters(service: any) {
		return (
			(service.id && service.id.toLowerCase().includes(getStore(search).toLowerCase())) ||
			(service.name && service.name.toLowerCase().includes(getStore(search).toLowerCase())) ||
			(service.type && service.type.toLowerCase().includes(getStore(search).toLowerCase())) ||
			(service.fqdn && service.fqdn.toLowerCase().includes(getStore(search).toLowerCase())) ||
			(service.version && service.version.toLowerCase().includes(getStore(search).toLowerCase())) ||
			(service.destinationDockerId &&
				service.destinationDocker.name.toLowerCase().includes(getStore(search).toLowerCase()))
		);
	}
export function gitSourceFilters(source: any) {
		return (
			(source.id && source.id.toLowerCase().includes(getStore(search).toLowerCase())) ||
			(source.name && source.name.toLowerCase().includes(getStore(search).toLowerCase())) ||
			(source.type && source.type.toLowerCase().includes(getStore(search).toLowerCase())) ||
			(source.htmlUrl && source.htmlUrl.toLowerCase().includes(getStore(search).toLowerCase())) ||
			(source.apiUrl && source.apiUrl.toLowerCase().includes(getStore(search).toLowerCase()))
		);
	}
export function destinationFilters(destination: any) {
		return (
			(destination.id && destination.id.toLowerCase().includes(getStore(search).toLowerCase())) ||
			(destination.name && destination.name.toLowerCase().includes(getStore(search).toLowerCase())) ||
			(destination.type && destination.type.toLowerCase().includes(getStore(search).toLowerCase()))
		);
	}
export function doSearch(bang?: string) {
		if (bang || bang === '') search.update( (v) => bang );
		if (getStore(search)) {
			filtered = setInitials();
			if (getStore(search).startsWith('!')) {
				if (getStore(search) === '!running') {
					filterState('running');
				} else if (getStore(search) === '!stopped') {
					filterState('stopped');
				} else if (getStore(search) === '!error') {
					filterState('error');
				} else if (getStore(search) === '!app') {
					filterSpecific('applications');
				} else if (getStore(search) === '!db') {
					filterSpecific('databases');
				} else if (getStore(search) === '!service') {
					filterSpecific('services');
				} else if (getStore(search) === '!git') {
					filterSpecific('gitSources');
				} else if (getStore(search) === '!destination') {
					filterSpecific('destinations');
				} else if (getStore(search) === '!bot') {
					clearFiltered();
					filtered.applications = applications.filter((application: any) => {
						return application.settings.isBot;
					});
					filtered.otherApplications = applications.filter((application: any) => {
						return application.settings.isBot && application.teams[0].id !== $appSession.teamId;
					});
				} else if (getStore(search) === '!notmine') {
					clearFiltered();
					filtered = setInitials(true);
				}
			} else {
				filtered.applications = filtered.applications.filter((application: any) =>
					applicationFilters(application)
				);
				filtered.otherApplications = filtered.otherApplications.filter((application: any) =>
					applicationFilters(application)
				);
				filtered.databases = filtered.databases.filter((database: any) =>
					databaseFilters(database)
				);
				filtered.otherDatabases = filtered.otherDatabases.filter((database: any) =>
					databaseFilters(database)
				);
				filtered.services = filtered.services.filter((service: any) => serviceFilters(service));
				filtered.otherServices = filtered.otherServices.filter((service: any) =>
					serviceFilters(service)
				);
				filtered.gitSources = filtered.gitSources.filter((source: any) => gitSourceFilters(source));
				filtered.otherGitSources = filtered.otherGitSources.filter((source: any) =>
					gitSourceFilters(source)
				);
				filtered.destinations = filtered.destinations.filter((destination: any) =>
					destinationFilters(destination)
				);
				filtered.otherDestinations = filtered.otherDestinations.filter((destination: any) =>
					destinationFilters(destination)
				);
			}
		} else {
			filtered = setInitials();
		}
	}
