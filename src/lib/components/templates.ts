const defaultBuildAndDeploy = {
	installCommand: 'yarn install',
	buildCommand: 'yarn build',
	startCommand: 'yarn start'
};

const templates = {
	svelte: {
		buildPack: 'svelte',
		...defaultBuildAndDeploy,
		publishDirectory: 'public',
		port: 80,
		name: 'Svelte'
	},
	'@nestjs/core': {
		buildPack: 'nestjs',
		...defaultBuildAndDeploy,
		startCommand: 'yarn start:prod',
		port: 3000,
		name: 'NestJS'
	},
	next: {
		buildPack: 'nextjs',
		...defaultBuildAndDeploy,
		port: 3000,
		name: 'NextJS'
	},
	nuxt: {
		buildPack: 'nuxtjs',
		...defaultBuildAndDeploy,
		port: 3000,
		name: 'NuxtJS'
	},
	'react-scripts': {
		buildPack: 'react',
		...defaultBuildAndDeploy,
		publishDirectory: 'build',
		port: 80,
		name: 'React'
	},
	'parcel-bundler': {
		buildPack: 'static',
		...defaultBuildAndDeploy,
		publishDirectory: 'dist',
		port: 80,
		name: 'Parcel'
	},
	'@vue/cli-service': {
		buildPack: 'vuejs',
		...defaultBuildAndDeploy,
		publishDirectory: 'dist',
		port: 80,
		name: 'Vue'
	},
	vuejs: {
		buildPack: 'vuejs',
		...defaultBuildAndDeploy,
		publishDirectory: 'dist',
		port: 80,
		name: 'Vue'
	},
	gatsby: {
		buildPack: 'gatsby',
		...defaultBuildAndDeploy,
		publishDirectory: 'public',
		port: 80,
		name: 'Gatsby'
	},
	'preact-cli': {
		buildPack: 'react',
		...defaultBuildAndDeploy,
		publishDirectory: 'build',
		port: 80,
		name: 'Preact'
	}
};

export default templates;
