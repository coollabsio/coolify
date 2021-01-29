const mongoose = require("mongoose");
const logSchema = mongoose.Schema(
  {
    deployId: { type: String, required: true },
    events: { type: Object, required: true, default: []},
  },
  // Only createdAt!
  { timestamps: true }
);

module.exports = mongoose.model("log", logSchema);