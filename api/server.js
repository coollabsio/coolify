require('dotenv').config()
const fs = require('fs')
const util = require('util')
const axios = require('axios')
const mongoose = require('mongoose')
const path = require('path')
const { saveServerLog } = require('./libs/logging')
const { execShellAsync } = require('./libs/common')
const { purgeImagesContainers, cleanupStuckedDeploymentsInDB } = require('./libs/applications/cleanup')
const fastify = require('fastify')({
  trustProxy: true,
  logger: {
    level: 'error'
  }
})
fastify.register(require('../api/libs/http-error'))

const { schema } = require('./schema')

process.on('unhandledRejection', async (reason, p) => {
  await saveServerLog({ message: reason.message, type: 'unhandledRejection' })
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
  try {
    // Always cleanup server logs
    await mongoose.connection.db.dropCollection('logs-servers')
  } catch (error) {
    // Could not cleanup logs-servers collection
  }
  // On start cleanup inprogress/queued deployments.
  try {
    await cleanupStuckedDeploymentsInDB()
  } catch (error) {
    // Could not cleanup DB 🤔
  }
  try {
    // Doing because I do not want to prune these images. Prune skips coolify-reserve labeled images.
    const basicImages = ['nginx:stable-alpine', 'node:lts', 'ubuntu:20.04', 'php:apache', 'rust:latest']
    for (const image of basicImages) {
      // await execShellAsync(`echo "FROM ${image}" | docker build --label coolify-reserve=true -t ${image} -`)
      await execShellAsync(`docker pull ${image}`)
    }
  } catch (error) {
    console.log('Could not pull some basic images from Docker Hub.')
    console.log(error)
  }
})
