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
		name: 'Svelte'
	},
	'@nestjs/core': {
		buildPack: 'nestjs',
		...defaultBuildAndDeploy,
		start: 'yarn start:prod',
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
		name: 'React'
	},
	'parcel-bundler': {
		buildPack: 'static',
		...defaultBuildAndDeploy,
		publishDirectory: 'dist',
		name: 'Parcel'
	},
	'@vue/cli-service': {
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
		name: 'Gatsby'
	},
	'preact-cli': {
		buildPack: 'react',
		...defaultBuildAndDeploy,
		publishDirectory: 'build',
		name: 'Preact'
	}
};

export default templates;