require('dotenv').config()
const fs = require('fs')
const util = require('util')
const { saveServerLog } = require('./libs/logging')
const Deployment = require('./models/Deployment')
const fastify = require('fastify')({
  logger: { level: 'error' }
})
const mongoose = require('mongoose')
const path = require('path')
const { schema } = require('./schema')

fastify.register(require('fastify-env'), {
  schema,
  dotenv: true
})

if (process.env.NODE_ENV === 'production') {
  fastify.register(require('fastify-static'), {
    root: path.join(__dirname, '../dist/')
  })

  fastify.setNotFoundHandler(function (request, reply) {
    reply.sendFile('index.html')
  })
} else {
  fastify.register(require('fastify-static'), {
    root: path.join(__dirname, '../public/')
  })
}

fastify.register(require('./app'), { prefix: '/api/v1' })
fastify.setErrorHandler(async (error, request, reply) => {
  console.log(error)
  if (error.statusCode) {
    reply.status(error.statusCode).send({ message: error.message } || { message: 'Something is NOT okay. Are you okay?' })
  } else {
    reply.status(500).send({ message: error.message } || { message: 'Something is NOT okay. Are you okay?' })
  }
  await saveServerLog({ event: error })
})

if (process.env.NODE_ENV === 'production') {
  mongoose.connect(
    `mongodb://${process.env.MONGODB_USER}:${process.env.MONGODB_PASSWORD}@${process.env.MONGODB_HOST}:${process.env.MONGODB_PORT}/${process.env.MONGODB_DB}?authSource=${process.env.MONGODB_DB}&readPreference=primary&ssl=false`,
    { useNewUrlParser: true, useUnifiedTopology: true, useFindAndModify: false }
  )
} else {
  mongoose.connect(
    'mongodb://localhost:27017/coolify?&readPreference=primary&ssl=false',
    { useNewUrlParser: true, useUnifiedTopology: true, useFindAndModify: false }
  )
}

mongoose.connection.on(
  'error',
  console.error.bind(console, 'connection error:')
)
mongoose.connection.once('open', async function () {
  if (process.env.NODE_ENV === 'production') {
    fastify.listen(3000, '0.0.0.0')
    console.log('Coolify API is up and running in production.')
  } else {
    const logFile = fs.createWriteStream('api/development/console.log', { flags: 'w' })
    const logStdout = process.stdout

    console.log = function (d) {
      logFile.write(`[INFO]: ${util.format(d)}\n`)
      logStdout.write(util.format(d) + '\n')
    }

    console.error = function (d) {
      logFile.write(`[ERROR]: ${util.format(d)}\n`)
      logStdout.write(util.format(d) + '\n')
    }

    console.warn = function (d) {
      logFile.write(`[WARN]: ${util.format(d)}\n`)
      logStdout.write(util.format(d) + '\n')
    }

    fastify.listen(3001)
    console.log('Coolify API is up and running in development.')
  }
  // On start cleanup inprogress/queued deployments.
  const deployments = await Deployment.find({ progress: { $in: ['queued', 'inprogress'] } })
  for (const deployment of deployments) {
    await Deployment.findByIdAndUpdate(deployment._id, { $set: { progress: 'failed' } })
  }
})
