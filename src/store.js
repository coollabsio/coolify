import { writable, derived, readable } from "svelte/store";

const sessionStore = {
  token: localStorage.getItem("token") || null,
  githubAppToken: null,
}; 

export const fetch = writable(
  async (
    url,
    { method, body, ...customConfig } = { body: null, method: null }
  ) => {
    let headers = { "Content-type": "application/json; charset=UTF-8" };
    const isGithub = url.match(/api.github.com/);
    if (isGithub) {
      headers = Object.assign(headers, {
        Authorization: `token ${sessionStore.githubAppToken}`,
      });
    } else {
      headers = Object.assign(headers, {
        Authorization: `Bearer ${sessionStore.token}`,
      });
    }
    const config = {
      cache: "no-cache",
      method: method ? method : body ? "POST" : "GET",
      ...customConfig,
      headers: {
        ...headers,
        ...customConfig.headers,
      },
    };
    if (body) {
      config.body = JSON.stringify(body);
    }
    const response = await window.fetch(url, config);
    if (response.status >= 200 && response.status <= 299) {
      if (response.headers.get("content-type").match(/application\/json/)) {
        return await response.json();
      } else if (response.headers.get("content-type").match(/text\/plain/)) {
        return await response.text();
      } else if (response.headers.get("content-type").match(/multipart\/form-data/)) {
        return await response.formData()
      } else {
        return await response.blob()
      }
    } else {
      if (response.status === 401) {
        return Promise.reject({
          code: response.status,
          error: "Unauthorized",
        });
      } else if (response.status >= 500) {
        const error = (await response.json()).message
        return Promise.reject({
          code: response.status,
          error: error ? error : 'Oops, something is not okay. Are you okay?',
        });
      } else {
        return Promise.reject({
          code: response.status,
          error: response.statusText,
        });
      }
    }
  }
);
export const session = writable(sessionStore);
export const loggedIn = derived(session, ($session) => {
  return $session.token;
});
export const savedBranch = writable();

export const dateOptions = readable({
  year: "numeric",
  month: "short",
  day: "2-digit",
  hour: "numeric",
  minute: "numeric",
  second: "numeric",
  hour12: false,
})
