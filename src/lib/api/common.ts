import User from '$models/User'
import jsonwebtoken from 'jsonwebtoken'

export const publicPages = ['/', '/api/v1/login/github/app'];
export const deleteCookies = [
    `coolToken=deleted; Path=/; HttpOnly; expires=Thu, 01 Jan 1970 00:00:00 GMT`,
    `ghToken=deleted; Path=/; HttpOnly; expires=Thu, 01 Jan 1970 00:00:00 GMT`
]

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