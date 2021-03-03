const mongoose = require("mongoose");

const configSchemaNew = mongoose.Schema(
  {
    general: {
      nickname: { type: String, required: true },
      previewDeploy: { type: Boolean, required: true, default: false },
    },
    github: {
      appId: { type: Number, required: true }, 
      installationId: { type: Number, required: true },
      id: { type: Number, required: true },
      name: { type: String, required: true },
      branch: { type: String, required: true },
    },
    build: {
      container: {
        name: { type: String, required: true },
        tag: { type: String, required: true },
      },
      base: { type: String, required: true },
      commands: {
        install: { type: String, required: true },
        build: { type: String, required: true },
      }
    },
    deploy: {
      domain: { 
        base: {type: String, required: true },
        preview: {type: String, required: true },
      },
      path : { type: String, required: true },
      port: { type: Number, required: true },
      secrets: { type: Array, required: true, default: [] },
      directory: { type: String, required: true },
    }
  },
  { timestamps: true }
);

const configSchema = mongoose.Schema(
  {
    repoId: { type: Number, required: true },
    installationId: { type: Number, required: true },
    fullName: { type: String, required: true },
    branch: { type: String, required: true },
    buildPack: { type: String, required: true },
    previewDeploy: { type: Boolean, required: true, default: false },
    containerName: { type: String, required: true },
    build: {
      baseDir: { type: String, default: null },
      installCmd: { type: String, default: null },
      buildCmd: { type: String, default: null },
    },
    publish: {
      publishDir: { type: String, default: null },
      domain: { type: String, default: null },
      pathPrefix: { type: String, default: null },
      port: { type: Number, default: null, required: true },
    },
  },
  { timestamps: true }
);
// const configModel = mongoose.model("config", configSchema);

// async function updateSchema() {
//   const update = await configModel.updateMany(
//     { previewDeploy: { $exists: false } },
//     { $set: { previewDeploy: false } },
//     { timestamps: false }
//   )
//   console.log(`configSchema updated for ${update.nModified} documents.`)
// }

// updateSchema()

module.exports = mongoose.model("config", configSchema)

