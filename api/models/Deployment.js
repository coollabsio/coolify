const mongoose = require("mongoose");
const deploymentSchema = mongoose.Schema(
  {
    domain: { type: String, required: true },
    repoId: { type: Number, required: true },
    progress: { type: String, require: true, default: "queued"},
    branch: { type: String, required: true },
    deployId: { type: String, required: true },
  },
  { timestamps: true }
);

module.exports = mongoose.model("deployment", deploymentSchema);