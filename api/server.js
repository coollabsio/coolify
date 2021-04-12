require('dotenv').config()
const fs = require('fs')
const util = require('util')
const { saveServerLog } = require('./libs/logging')
const { execShellAsync } = require('./libs/common')
const { purgeImagesContainers, cleanupStuckedDeploymentsInDB } = require('./libs/applications/cleanup')
const Deployment = require('./models/Deployment')
const fastify = require('fastify')({
  logger: { level: 'error' }
})
const mongoose = require('mongoose')
const path = require('path')
const { schema } = require('./schema')

process.on('unhandledRejection', (reason, p) => {
  console.log(reason)
  console.log(p)
})
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
  if (error.statusCode) {
    reply.status(error.statusCode).send({ message: error.message } || { message: 'Something is NOT okay. Are you okay?' })
  } else {
    reply.status(500).send({ message: error.message } || { message: 'Something is NOT okay. Are you okay?' })
  }
  try {
    await saveServerLog({ event: error })
  } catch (error) {
    //
  }
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
  try {
    await cleanupStuckedDeploymentsInDB()
  } catch (error) {
    // Could not cleanup DB 🤔
  }
  try {
    // Doing because I do not want to prune these images. Prune skip coolify-reserve labeled images.
    const basicImages = ['nginx:stable-alpine', 'node:lts', 'ubuntu:20.04']
    for (const image of basicImages) {
      await execShellAsync(`echo "FROM ${image}" | docker build --label coolify-reserve=true -t ${image} -`)
    }
  } catch (error) {
    console.log('Could not pull some basic images from Docker Hub.')
    console.log(error)
  }
  try {
    await purgeImagesContainers()
  } catch (error) {
    console.log('Could not purge containers/images.')
    console.log(error)
  }
})
