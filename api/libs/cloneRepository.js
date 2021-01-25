const jwt = require("jsonwebtoken");
const axios = require("axios");
const { execShellAsync } = require("./common");

module.exports = async function (config, appId, privateKey) {
  const { workdir } = config.general;
  const { name, branch, installationId } = config.repository;
  const githubPrivateKey = privateKey.replace(/\\n/g, "\n").replace(/\"/g, "");
  const payload = {
    iat: Math.round(new Date().getTime() / 1000),
    exp: Math.round(new Date().getTime() / 1000 + 60),
    iss: parseInt(appId),
  };

  try {
    const jwtToken = jwt.sign(payload, githubPrivateKey, {
      algorithm: "RS256",
    });
    const accessToken = await axios({
      method: "POST",
      url: `https://api.github.com/app/installations/${installationId}/access_tokens`,
      data: {},
      headers: {
        Authorization: "Bearer " + jwtToken,
        Accept: "application/vnd.github.machine-man-preview+json",
      },
    });
    await execShellAsync(
      `mkdir -p ${workdir} && git clone -q -b ${branch} https://x-access-token:${accessToken.data.token}@github.com/${name}.git ${workdir}/`
    );
  } catch (error) {
    if (error.stack) console.log(error.stack);
    throw new Error(error);
  }
};
