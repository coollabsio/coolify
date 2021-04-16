const axios = require('axios')
const User = require('../../../models/User')
const Settings = require('../../../models/Settings')
const cuid = require('cuid')
const mongoose = require('mongoose')
const jwt = require('jsonwebtoken')
const { saveServerLog } = require('../../../libs/logging')

module.exports = async function (fastify) {
  const githubCodeSchema = {
    schema: {
      querystring: {
        type: 'object',
        properties: {
          code: { type: 'string' }
        },
        required: ['code']
      }
    }
  }
  fastify.get('/app', { schema: githubCodeSchema }, async (request, reply) => {
    const { code } = request.query
    try {
      const { data } = await axios({
        method: 'post',
        url: `https://github.com/login/oauth/access_token?client_id=${fastify.config.VITE_GITHUB_APP_CLIENTID}&client_secret=${fastify.config.GITHUB_APP_CLIENT_SECRET}&code=${code}`,
        headers: {
          accept: 'application/json'
        }
      })

      const token = data.access_token
      const githubAxios = axios.create({
        baseURL: 'https://api.github.com'
      })

      githubAxios.defaults.headers.common.Accept = 'Application/json'
      githubAxios.defaults.headers.common.Authorization = `token ${token}`

      try {
        let uid = cuid()
        const { avatar_url } = (await githubAxios.get('/user')).data // eslint-disable-line
        const email = (await githubAxios.get('/user/emails')).data.filter(
          (e) => e.primary
        )[0].email
        const settings = await Settings.findOne({ applicationName: 'coolify' })
        const registeredUsers = await User.find().countDocuments()
        const foundUser = await User.findOne({ email })
        if (foundUser) {
          await User.findOneAndUpdate(
            { email },
            { avatar: avatar_url },
            { upsert: true, new: true }
          )
          uid = foundUser.uid
        } else {
          if (registeredUsers === 0) {
            const newUser = new User({
              _id: new mongoose.Types.ObjectId(),
              email,
              avatar: avatar_url,
              uid
            })
            try {
              await newUser.save()
            } catch (e) {
              console.log(e)
              reply.code(500).send({ success: false, error: e })
              return
            }
          } else {
            if (!settings && registeredUsers > 0) {
              reply.code(500).send('Registration disabled, enable it in settings.')
            } else {
              if (!settings.allowRegistration) {
                reply.code(500).send('You are not allowed here!')
              } else {
                const newUser = new User({
                  _id: new mongoose.Types.ObjectId(),
                  email,
                  avatar: avatar_url,
                  uid
                })
                try {
                  await newUser.save()
                } catch (e) {
                  console.log(e)
                  reply.code(500).send({ success: false, error: e })
                  return
                }
              }
            }
          }
        }
        const jwtToken = jwt.sign({}, fastify.config.JWT_SIGN_KEY, {
          expiresIn: 15778800,
          algorithm: 'HS256',
          audience: 'coolLabs',
          issuer: 'coolLabs',
          jwtid: uid,
          subject: `User:${uid}`,
          notBefore: -1000
        })
        reply
          .code(200)
          .redirect(
            302,
            `/api/v1/login/github/success?jwtToken=${jwtToken}&ghToken=${token}`
          )
      } catch (e) {
        console.log(e)
        reply.code(500).send({ success: false, error: e })
        return
      }
    } catch (error) {
      await saveServerLog(error)
      throw new Error(error)
    }
  })
  fastify.get('/success', async (request, reply) => {
    return reply.sendFile('bye.html')
  })
}
