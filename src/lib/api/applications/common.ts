import User from '$models/User'
import jsonwebtoken from 'jsonwebtoken'

export async function verifyUserId(token) {
  const { JWT_SIGN_KEY } = process.env
  try {
    const verify = jsonwebtoken.verify(token, JWT_SIGN_KEY)
    const found = await User.findOne({ uid: verify.jti })
    if (found) {
      return true
    } else {
      return false
    }
  } catch (error) {
    console.log(error)
    return false
  }
}