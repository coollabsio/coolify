const defaultBuildAndDeploy = {
	installCommand: 'yarn install',
	buildCommand: 'yarn build',
	startCommand: 'yarn start'
};
export const buildPacks = [
	{
		name: 'node',
		installCommand: null,
		buildCommand: null,
		startCommand: null,
		publishDirectory: null,
		port: null,
		fancyName: 'Node.js',
		hoverColor: 'hover:bg-green-700',
		color: 'bg-green-700'
	},

	{
		name: 'static',
		...defaultBuildAndDeploy,
		publishDirectory: 'dist',
		port: 80,
		fancyName: 'Static',
		hoverColor: 'hover:bg-orange-700',
		color: 'bg-orange-700'
	},
	{
		name: 'docker',
		installCommand: null,
		buildCommand: null,
		startCommand: null,
		publishDirectory: null,
		port: null,
		fancyName: 'Docker',
		hoverColor: 'hover:bg-sky-700',
		color: 'bg-sky-700'
	},
	{
		name: 'svelte',
		...defaultBuildAndDeploy,
		publishDirectory: 'public',
		port: 80,
		fancyName: 'Svelte',
		hoverColor: 'hover:bg-orange-700',
		color: 'bg-orange-700'
	},
	{
		name: 'nestjs',
		...defaultBuildAndDeploy,
		startCommand: 'yarn start:prod',
		port: 3000,
		fancyName: 'NestJS',
		hoverColor: 'hover:bg-red-700',
		color: 'bg-red-700'
	},
	{
		name: 'react',
		...defaultBuildAndDeploy,
		publishDirectory: 'build',
		port: 80,
		fancyName: 'React',
		hoverColor: 'hover:bg-blue-700',
		color: 'bg-blue-700'
	},
	{
		name: 'nextjs',
		...defaultBuildAndDeploy,
		port: 3000,
		fancyName: 'NextJS',
		hoverColor: 'hover:bg-blue-700',
		color: 'bg-blue-700'
	},
	{
		name: 'gatsby',
		...defaultBuildAndDeploy,
		publishDirectory: 'public',
		port: 80,
		fancyName: 'Gatsby',
		hoverColor: 'hover:bg-blue-700',
		color: 'bg-blue-700'
	},
	{
		name: 'vuejs',
		...defaultBuildAndDeploy,
		publishDirectory: 'dist',
		port: 80,
		fancyName: 'VueJS',
		hoverColor: 'hover:bg-green-700',
		color: 'bg-green-700'
	},
	{
		name: 'nuxtjs',
		...defaultBuildAndDeploy,
		port: 3000,
		fancyName: 'NuxtJS',
		hoverColor: 'hover:bg-green-700',
		color: 'bg-green-700'
	},
	{
		name: 'preact',
		...defaultBuildAndDeploy,
		publishDirectory: 'build',
		port: 80,
		fancyName: 'Preact',
		hoverColor: 'hover:bg-blue-700',
		color: 'bg-blue-700'
	},
	{
		name: 'php',
		port: 80,
		fancyName: 'PHP',
		hoverColor: 'hover:bg-indigo-700',
		color: 'bg-indigo-700'
	},
	{
		name: 'rust',
		port: 3000,
		fancyName: 'Rust',
		hoverColor: 'hover:bg-pink-700',
		color: 'bg-pink-700'
	}
];
export const scanningTemplates = {
	svelte: {
		buildPack: 'svelte'
	},
	'@nestjs/core': {
		buildPack: 'nestjs'
	},
	next: {
		buildPack: 'nextjs'
	},
	nuxt: {
		buildPack: 'nuxtjs'
	},
	'react-scripts': {
		buildPack: 'react'
	},
	'parcel-bundler': {
		buildPack: 'static'
	},
	'@vue/cli-service': {
		buildPack: 'vuejs'
	},
	vuejs: {
		buildPack: 'vuejs'
	},
	gatsby: {
		buildPack: 'gatsby'
	},
	'preact-cli': {
		buildPack: 'react'
	}
};
