const defaultBuildAndDeploy = {
  installation: 'yarn install',
  build: 'yarn build'
}

const templates = {
  next: {
    pack: 'nodejs',
    ...defaultBuildAndDeploy,
    port: 3000,
    name: 'Next.js'
  },
  nuxt: {
    pack: 'nodejs',
    ...defaultBuildAndDeploy,
    port: 3000,
    name: 'Nuxt'
  },
  'react-scripts': {
    pack: 'static',
    ...defaultBuildAndDeploy,
    directory: 'build',
    name: 'Create React'
  },
  'parcel-bundler': {
    pack: 'static',
    ...defaultBuildAndDeploy,
    directory: 'dist',
    name: 'Parcel'
  },
  '@vue/cli-service': {
    pack: 'static',
    ...defaultBuildAndDeploy,
    directory: 'dist',
    name: 'Vue CLI'
  },
  gatsby: {
    pack: 'static',
    ...defaultBuildAndDeploy,
    directory: 'public',
    name: 'Gatsby'
  },
  'preact-cli': {
    pack: 'static',
    ...defaultBuildAndDeploy,
    directory: 'build',
    name: 'Preact CLI'
  }
}

export default templates
