module.exports = {
  optimizeDeps: {
    exclude: [
      "@roxi/routify",
      "fastify-static",
      "fastify",
      "fastify-autoload",
      "fastify-jwt",
      "dotenv",
      "dotenv-extended",
      "commander",
      "axios",
      "fastify-env",
      "fastify-plugin",
      "mongoose",
      "js-yaml",
      "shelljs",
      "jsonwebtoken",
      "deepmerge",
      "dockerode",
      "dayjs",
      "@zerodevx/svelte-toast"
    ]
  },
  proxy: {
    "/api": "http://127.0.0.1:3001/"
  }
};
