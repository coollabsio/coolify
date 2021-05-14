const defaultBuildAndDeploy = {
	installation: 'yarn install',
	build: 'yarn build'
};

const templates = {
	svelte: {
		pack: 'svelte',
		...defaultBuildAndDeploy,
		directory: 'public',
		name: 'Svelte'
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
