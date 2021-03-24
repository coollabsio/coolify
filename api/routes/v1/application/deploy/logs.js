const ApplicationLog = require('../../../../models/Logs/Application')
const Deployment = require('../../../../models/Deployment')
const dayjs = require('dayjs')
const utc = require('dayjs/plugin/utc')
const relativeTime = require('dayjs/plugin/relativeTime')
dayjs.extend(utc)
dayjs.extend(relativeTime)

module.exports = async function (fastify) {
  const getLogSchema = {
    querystring: {
      type: 'object',
      properties: {
        repoId: { type: 'string' },
        branch: { type: 'string' }
      },
      required: ['repoId', 'branch']
    }
  }
  fastify.get('/', { schema: getLogSchema }, async (request, reply) => {
    const { repoId, branch, page } = request.query
    const onePage = 5
    const show = Number(page) * onePage || 5
    const deploy = await Deployment.find({ repoId, branch })
      .select('-_id -__v -repoId')
      .sort({ createdAt: 'desc' })
      .limit(show)

    const finalLogs = deploy.map(d => {
      const finalLogs = { ...d._doc }

      const updatedAt = dayjs(d.updatedAt).utc()

      finalLogs.took = updatedAt.diff(dayjs(d.createdAt)) / 1000
      finalLogs.since = updatedAt.fromNow()

      return finalLogs
    })
    return finalLogs
  })

  fastify.get('/:deployId', async (request, reply) => {
    const { deployId } = request.params
    try {
      const logs = await ApplicationLog.find({ deployId })
        .select('-_id -__v')
        .sort({ createdAt: 'asc' })

      const deploy = await Deployment.findOne({ deployId })
        .select('-_id -__v')
        .sort({ createdAt: 'desc' })

      const finalLogs = {}
      finalLogs.progress = deploy.progress
      finalLogs.events = logs.map(log => log.event)
      finalLogs.human = dayjs(deploy.updatedAt).from(dayjs(deploy.updatedAt))
      return finalLogs
    } catch (e) {
      throw new Error('No logs found')
    }
  })
}
