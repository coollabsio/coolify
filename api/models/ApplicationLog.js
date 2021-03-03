const mongoose = require("mongoose");
const logSchema = mongoose.Schema(
  {
    deployId: { type: String, required: true },
    event: { type: String, required: true },
  },
  { timestamps: { createdAt: 'createdAt', updatedAt: false } }
);

module.exports = mongoose.model("applicationlog", logSchema);