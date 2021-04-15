module.exports = {
  alias: {
    '@store': '/src/store.js'
    // '/@components/': path.resolve(__dirname, '/src/components'),
  },
  optimizeDeps: {
    exclude: [
      '@roxi/routify',
      'fastify-static',
      'fastify',
      'fastify-autoload',
      'fastify-jwt',
      'dotenv',
      'dotenv-extended',
      'commander',
      'axios',
      'fastify-env',
      'fastify-plugin',
      'mongoose',
      'js-yaml',
      'shelljs',
      'jsonwebtoken',
      'deepmerge',
      'dockerode',
      'dayjs',
      '@zerodevx/svelte-toast',
      'mongodb-memory-server-core',
      'unique-names-generator',
      'generate-password',
      '@iarna/toml'
    ]
  },
  proxy: {
    '/api': 'http://127.0.0.1:3001/'
  }
}
