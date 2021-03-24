const mongoose = require('mongoose')
const { version } = require('../../../package.json')
const logSchema = mongoose.Schema(
  {
    version: { type: String, required: true, default: version },
    type: { type: String, required: true, enum: ['API', 'UPGRADE-P-1', 'UPGRADE-P-2'], default: 'API' },
    event: { type: String, required: true },
    seen: { type: Boolean, required: true, default: false }
  },
  { timestamps: { createdAt: 'createdAt', updatedAt: false } }
)

module.exports = mongoose.model('logs-server', logSchema)
