const mongoose = require('mongoose')

const settingsSchema = mongoose.Schema(
  {
    applicationName: { type: String, required: true, default: 'coolify' },
    allowRegistration: { type: Boolean, required: true, default: false },
    sendErrors: { type: Boolean, required: true, default: true }
  },
  { timestamps: true }
)

module.exports = mongoose.model('settings', settingsSchema)
