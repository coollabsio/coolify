const mongoose = require("mongoose");

const secretSchema = mongoose.Schema(
  {
    repoId: { type: Number, required: true },
    branch: { type: String, required: true },
    name: { type: String, required: true },
    value: { type: Object, required: true },
  },
  { timestamps: true }
);

module.exports = mongoose.model("secret", secretSchema);
