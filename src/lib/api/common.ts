import User from '$models/User'
import jsonwebtoken from 'jsonwebtoken'

export const publicPages = ['/', '/api/v1/login/github/app'];
export const deleteCookies = [
    `coolToken=deleted; Path=/; HttpOnly; expires=Thu, 01 Jan 1970 00:00:00 GMT`,
    `ghToken=deleted; Path=/; HttpOnly; expires=Thu, 01 Jan 1970 00:00:00 GMT`
]
export const baseServiceConfiguration = {
  replicas: 1,
  restart_policy: {
      condition: 'any',
      max_attempts: 6
  },
  update_config: {
      parallelism: 1,
      delay: '10s',
      order: 'start-first'
  },
  rollback_config: {
      parallelism: 1,
      delay: '10s',
      order: 'start-first',
      failure_action: 'rollback'
  }
}

export async function verifyUserId(token) {
  const { JWT_SIGN_KEY } = process.env
  try {
    const verify = jsonwebtoken.verify(token, JWT_SIGN_KEY)
    const found = await User.findOne({ uid: verify.jti })
    if (found) {
      return Promise.resolve(true)
    } else {
      return Promise.reject(false);
    }
  } catch (error) {
    console.log(error)
    return Promise.reject(false);
  }
}
export function delay (t) {
  return new Promise(function (resolve) {
    setTimeout(function () {
      resolve('OK')
    }, t)
  })
}