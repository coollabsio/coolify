function defaultBuildAndDeploy(packageManager) {
	return {
		installCommand:
			packageManager === 'npm' ? `${packageManager} install` : `${packageManager} install`,
		buildCommand:
			packageManager === 'npm' ? `${packageManager} run build` : `${packageManager} build`,
		startCommand:
			packageManager === 'npm' ? `${packageManager} run start` : `${packageManager} start`
	};
}
export function findBuildPack(pack, packageManager = 'npm') {
	const metaData = buildPacks.find((b) => b.name === pack);
	if (pack === 'node') {
		return {
			...metaData,
			...defaultBuildAndDeploy(packageManager),
			buildCommand: null,
			startCommand: null,
			publishDirectory: null,
			port: null
		};
	}
	if (pack === 'static') {
		return {
			...metaData,
			installCommand: null,
			buildCommand: null,
			startCommand: null,
			publishDirectory: null,
			port: 80
		};
	}
	if (pack === 'docker') {
		return {
			...metaData,
			installCommand: null,
			buildCommand: null,
			startCommand: null,
			publishDirectory: null,
			port: null
		};
	}
	if (pack === 'svelte') {
		return {
			...metaData,
			...defaultBuildAndDeploy(packageManager),
			publishDirectory: 'public',
			port: 80
		};
	}
	if (pack === 'nestjs') {
		return {
			...metaData,
			...defaultBuildAndDeploy(packageManager),
			startCommand:
				packageManager === 'npm' ? 'npm run start:prod' : `${packageManager} run start:prod`,
			publishDirectory: null,
			port: 3000
		};
	}
	if (pack === 'react') {
		return {
			...metaData,
			...defaultBuildAndDeploy(packageManager),
			publishDirectory: 'build',
			port: 80
		};
	}
	if (pack === 'nextjs') {
		return {
			...metaData,
			...defaultBuildAndDeploy(packageManager),
			publishDirectory: null,
			port: 3000
		};
	}
	if (pack === 'gatsby') {
		return {
			...metaData,
			...defaultBuildAndDeploy(packageManager),
			publishDirectory: 'public',
			port: 80
		};
	}
	if (pack === 'vuejs') {
		return {
			...metaData,
			...defaultBuildAndDeploy(packageManager),
			publishDirectory: 'dist',
			port: 80
		};
	}
	if (pack === 'nuxtjs') {
		return {
			...metaData,
			...defaultBuildAndDeploy(packageManager),
			publishDirectory: null,
			port: 3000
		};
	}
	if (pack === 'preact') {
		return {
			...metaData,
			...defaultBuildAndDeploy(packageManager),
			publishDirectory: 'build',
			port: 80
		};
	}
	if (pack === 'php') {
		return {
			...metaData,
			installCommand: null,
			buildCommand: null,
			startCommand: null,
			publishDirectory: null,
			port: 80
		};
	}
	if (pack === 'rust') {
		return {
			...metaData,
			installCommand: null,
			buildCommand: null,
			startCommand: null,
			publishDirectory: null,
			port: 3000
		};
	}
	if (pack === 'astro') {
		return {
			...metaData,
			installCommand: `yarn install`,
			buildCommand: `yarn build`,
			startCommand: null,
			publishDirectory: `dist`,
			port: 80
		};
	}
	if (pack === 'eleventy') {
		return {
			...metaData,
			installCommand: `yarn install`,
			buildCommand: `yarn build`,
			startCommand: null,
			publishDirectory: `_site`,
			port: 80
		};
	}
	if (pack === 'python') {
		return {
			...metaData,
			startCommand: null,
			port: 8000
		};
	}
	return {
		name: 'node',
		fancyName: 'Node.js',
		hoverColor: 'hover:bg-green-700',
		color: 'bg-green-700',
		installCommand: null,
		buildCommand: null,
		startCommand: null,
		publishDirectory: null,
		port: null
	};
}
export const buildPacks = [
	{
		name: 'node',
		fancyName: 'Node.js',
		hoverColor: 'hover:bg-green-700',
		color: 'bg-green-700'
	},
	{
		name: 'static',
		fancyName: 'Static',
		hoverColor: 'hover:bg-orange-700',
		color: 'bg-orange-700'
	},
	{
		name: 'docker',
		fancyName: 'Docker',
		hoverColor: 'hover:bg-sky-700',
		color: 'bg-sky-700'
	},
	{
		name: 'php',
		fancyName: 'PHP',
		hoverColor: 'hover:bg-indigo-700',
		color: 'bg-indigo-700'
	},
	{
		name: 'svelte',
		fancyName: 'Svelte',
		hoverColor: 'hover:bg-orange-700',
		color: 'bg-orange-700'
	},
	{
		name: 'vuejs',
		fancyName: 'VueJS',
		hoverColor: 'hover:bg-green-700',
		color: 'bg-green-700'
	},
	{
		name: 'nuxtjs',
		fancyName: 'NuxtJS',
		hoverColor: 'hover:bg-green-700',
		color: 'bg-green-700'
	},
	{
		name: 'gatsby',
		fancyName: 'Gatsby',
		hoverColor: 'hover:bg-blue-700',
		color: 'bg-blue-700'
	},
	{
		name: 'astro',
		fancyName: 'Astro',
		hoverColor: 'hover:bg-pink-700',
		color: 'bg-pink-700'
	},
	{
		name: 'eleventy',
		fancyName: 'Eleventy',
		hoverColor: 'hover:bg-red-700',
		color: 'bg-red-700'
	},

	{
		name: 'react',
		fancyName: 'React',
		hoverColor: 'hover:bg-blue-700',
		color: 'bg-blue-700'
	},
	{
		name: 'preact',
		fancyName: 'Preact',
		hoverColor: 'hover:bg-blue-700',
		color: 'bg-blue-700'
	},
	{
		name: 'nextjs',
		fancyName: 'NextJS',
		hoverColor: 'hover:bg-blue-700',
		color: 'bg-blue-700'
	},
	{
		name: 'nestjs',
		fancyName: 'NestJS',
		hoverColor: 'hover:bg-red-700',
		color: 'bg-red-700'
	},
	{
		name: 'rust',
		fancyName: 'Rust',
		hoverColor: 'hover:bg-pink-700',
		color: 'bg-pink-700'
	},
	{
		name: 'python',
		fancyName: 'Python',
		hoverColor: 'hover:bg-green-700',
		color: 'bg-green-700'
	}
];
export const scanningTemplates = {
	'@sveltejs/kit': {
		buildPack: 'nodejs'
	},
	astro: {
		buildPack: 'astro'
	},
	'@11ty/eleventy': {
		buildPack: 'eleventy'
	},
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
