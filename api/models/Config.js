const mongoose = require("mongoose");

const configSchema = mongoose.Schema(
  {
    repoId: { type: Number, required: true },
    installationId: { type: Number, required: true },
    fullName: { type: String, required: true },
    branch: { type: String, required: true },
    buildPack: { type: String, required: true },
    build: {
      required: { type: Boolean, default: false },
      baseDir: { type: String, default: null },
      installCmd: { type: String, default: null },
      buildCmd: { type: String, default: null },
    },
    publish: {
      baseDir: { type: String, default: null },
      domain: { type: String, default: null },
      path: { type: String, default: null },
      port: { type: Number, default: null, required: true },
    },
  },
  { timestamps: true }
);

module.exports = mongoose.model("config", configSchema);
