const defaultBuildAndDeploy = {
	installation: 'yarn install',
	build: 'yarn build',
	start: 'yarn start'
};

const templates = {
	svelte: {
		pack: 'svelte',
		...defaultBuildAndDeploy,
		directory: 'public',
		name: 'Svelte'
	},
	'@nestjs/core': {
		pack: 'nestjs',
		...defaultBuildAndDeploy,
		start: 'yarn start:prod',
		port: 3000,
		name: 'NestJS'
	},
	next: {
		pack: 'nextjs',
		...defaultBuildAndDeploy,
		port: 3000,
		name: 'NextJS'
	},
	nuxt: {
		pack: 'nuxtjs',
		...defaultBuildAndDeploy,
		port: 3000,
		name: 'NuxtJS'
	},
	'react-scripts': {
		pack: 'react',
		...defaultBuildAndDeploy,
		directory: 'build',
		name: 'React'
	},
	'parcel-bundler': {
		pack: 'static',
		...defaultBuildAndDeploy,
		directory: 'dist',
		name: 'Parcel'
	},
	'@vue/cli-service': {
		pack: 'vuejs',
		...defaultBuildAndDeploy,
		directory: 'dist',
		port: 80,
		name: 'Vue'
	},
	gatsby: {
		pack: 'gatsby',
		...defaultBuildAndDeploy,
		directory: 'public',
		name: 'Gatsby'
	},
	'preact-cli': {
		pack: 'react',
		...defaultBuildAndDeploy,
		directory: 'build',
		name: 'Preact'
	}
};

export default templates;