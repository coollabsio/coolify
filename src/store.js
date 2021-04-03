import { writable, derived, readable } from 'svelte/store'

const sessionStore = {
  token: window.localStorage.getItem('token') || null,
  githubAppToken: null
}

function waitAtLeast (time, promise) {
  const timeoutPromise = new Promise((resolve) => {
    setTimeout(resolve, time)
  })
  return Promise.all([promise, timeoutPromise]).then((values) => values[0])
};

export const fetch = writable(
  async (
    url,
    { method, body, ...customConfig } = { body: null, method: null }
  ) => {
    let headers = { 'Content-type': 'application/json; charset=UTF-8' }
    if (method === 'DELETE') {
      delete headers['Content-type']
    }
    const isGithub = url.match(/api.github.com/)
    if (isGithub) {
      headers = Object.assign(headers, {
        Authorization: `token ${sessionStore.githubAppToken}`
      })
    } else {
      headers = Object.assign(headers, {
        Authorization: `Bearer ${sessionStore.token}`
      })
    }
    const config = {
      cache: 'no-cache',
      method: method || (body ? 'POST' : 'GET'),
      ...customConfig,
      headers: {
        ...headers,
        ...customConfig.headers
      }
    }
    if (body) {
      config.body = JSON.stringify(body)
    }
    const response = await waitAtLeast(350, window.fetch(url, config))
    if (response.status >= 200 && response.status <= 299) {
      if (response.headers.get('content-type').match(/application\/json/)) {
        return await response.json()
      } else if (response.headers.get('content-type').match(/text\/plain/)) {
        return await response.text()
      } else if (response.headers.get('content-type').match(/multipart\/form-data/)) {
        return await response.formData()
      } else {
        return await response.blob()
      }
    } else {
      /* eslint-disable */
      if (response.status === 401) {
        return Promise.reject({
          code: response.status,
          error: 'Unauthorized'
        })
      } else if (response.status >= 500) {
        const error = (await response.json()).message
        return Promise.reject({
          code: response.status,
          error: error || 'Oops, something is not okay. Are you okay?'
        })
      } else {
        return Promise.reject({
          code: response.status,
          error: response.statusText
        })
      }
      /* eslint-enable */
    }
  }
)
export const session = writable(sessionStore)
export const loggedIn = derived(session, ($session) => {
  return $session.token
})
export const savedBranch = writable()

export const dateOptions = readable({
  year: 'numeric',
  month: 'short',
  day: '2-digit',
  hour: 'numeric',
  minute: 'numeric',
  second: 'numeric',
  hour12: false
})

export const deployments = writable({})

export const initConf = writable({})
export const application = writable({
  github: {
    installation: {
      id: null
    },
    app: {
      id: null
    }
  },
  repository: {
    id: null,
    organization: 'new',
    name: 'start',
    branch: null,
  },
  general: {
    deployId: null,
    nickname: null,
    workdir: null
  },
  build: {
    pack: 'static',
    directory: null,
    command: {
      build: null,
      installation: null
    },
    container: {
      name: null,
      tag: null,
      baseSHA: null
    }
  },
  publish: {
    directory: null,
    domain: null,
    path: '/',
    port: null,
    secrets: []
  }
})

export const initialApplication = {
  github: {
    installation: {
      id: null
    },
    app: {
      id: null
    }
  },
  repository: {
    id: null,
    organization: 'new',
    name: 'start',
    branch: null,
  },
  general: {
    deployId: null,
    nickname: null,
    workdir: null
  },
  build: {
    pack: 'static',
    directory: null,
    command: {
      build: null,
      installation: null
    },
    container: {
      name: null,
      tag: null
    }
  },
  publish: {
    directory: null,
    domain: null,
    path: '/',
    port: null,
    secrets: []
  }
}
export const initialDatabase = {
  config: {
    general: {
      workdir: null,
      deployId: null,
      nickname: null,
      type: null
    },
    database: {
      username: null,
      passwords: [],
      defaultDatabaseName: null
    },
    deploy: {
      name: null
    }
  },
  envs: {}
}

export const database = writable({
  config: {
    general: {
      workdir: null,
      deployId: null,
      nickname: null,
      type: null
    },
    database: {
      username: null,
      passwords: [],
      defaultDatabaseName: null
    },
    deploy: {
      name: null
    }
  },
  envs: {}
})

export const dbInprogress = writable(false)
