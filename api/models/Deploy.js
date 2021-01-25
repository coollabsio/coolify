const mongoose = require("mongoose");

const deploySchema = mongoose.Schema(
  {
    repoId: { type: Number, required: true },
    branch: { type: String, required: true },
    deployId: { type: String, required: true },
    events: { type: Object, required: true, default: ["Queued for building."]},
    progress: { type: String, required: true, default: 'queued'}
  },
  { timestamps: true }
);

module.exports = mongoose.model("deploy", deploySchema);
