const mongoose = require('mongoose')
const { version } = require('../../../package.json')
const logSchema = mongoose.Schema(
  {
    version: { type: String, default: version },
    type: { type: String, required: true },
    message: { type: String, required: true },
    stack: { type: String },
    seen: { type: Boolean, default: false }
  },
  { timestamps: { createdAt: 'createdAt', updatedAt: false } }
)

module.exports = mongoose.model('logs-server', logSchema)
