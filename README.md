# Coolify

An open-source & self-hostable Heroku / Netlify alternative.

## Live Demo

https://demo.coolify.io/

(If it is unresponsive, that means someone overloaded the server. 😄)

## Feedback

If you have a new service / build pack you would like to add, raise an idea [here](https://feedback.coolify.io/) to get feedback from the community!

--- 

## How to install

For more details goto the [docs](https://docs.coollabs.io/coolify/installation).

Installation is automated with the following command:

```bash
wget -q https://get.coollabs.io/coolify/install.sh -O install.sh; sudo bash ./install.sh
```

If you would like no questions during installation:

```bash
wget -q https://get.coollabs.io/coolify/install.sh -O install.sh; sudo bash ./install.sh -f
```

--- 

## Features

### Git Sources

Self-hosted versions also!

<a href="https://github.com"><img style="width:40px;height:40px" src="https://icon.horse/icon/github.com"></a>
<a href="https://gitlab.com"><img style="width:40px;height:40px" src="https://icon.horse/icon/gitlab.com"></a>

### Destinations

Deploy your resource to:

- Local Docker Engine
- Remote Docker Engine

### Applications

<a href="https://heroku.com"><img style="width:40px;height:40px" src="https://icon.horse/icon/heroku.com"></a>
<a href="https://html5.org/">
<svg style="width:40px;height:40px" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg" ><g clip-path="url(#HTML5_Clip0_4)" ><path d="M30.216 0L27.6454 28.7967L16.0907 32L4.56783 28.8012L2 0H30.216Z" fill="#E44D26" /><path d="M16.108 29.5515L25.4447 26.963L27.6415 2.35497H16.108V29.5515Z" fill="#F16529" /><path d="M11.1109 9.4197H16.108V5.88731H7.25053L7.33509 6.83499L8.20327 16.5692H16.108V13.0369H11.4338L11.1109 9.4197Z" fill="#EBEBEB" /><path d="M11.907 18.3354H8.36111L8.856 23.8818L16.0917 25.8904L16.108 25.8859V22.2108L16.0925 22.2149L12.1585 21.1527L11.907 18.3354Z" fill="#EBEBEB" /><path d="M16.0958 16.5692H20.4455L20.0354 21.1504L16.0958 22.2138V25.8887L23.3373 23.8817L23.3904 23.285L24.2205 13.9855L24.3067 13.0369H16.0958V16.5692Z" fill="white" /><path d="M16.0958 9.41105V9.41969H24.6281L24.6989 8.62572L24.8599 6.83499L24.9444 5.88731H16.0958V9.41105Z" fill="white" /></g><defs><clipPath id="HTML5_Clip0_4"><rect width="32" height="32" fill="white" /></clipPath></defs></svg></a>
<a href="https://nodejs.org"><img style="width:40px;height:40px" src="https://icon.horse/icon/nodejs.org"></a>
<a href="https://vuejs.org"><img style="width:40px;height:40px" src="https://icon.horse/icon/vuejs.org"></a>
<a href="https://nuxtjs.org"><img style="width:40px;height:40px" src="https://icon.horse/icon/nuxtjs.org"></a>
<a href="https://nextjs.org"><img style="width:40px;height:40px" src="https://icon.horse/icon/nextjs.org"></a>
<a href="https://reactjs.org"><img style="width:40px;height:40px" src="https://icon.horse/icon/reactjs.org"></a>
<a href="https://preactjs.org"><img style="width:40px;height:40px" src="https://icon.horse/icon/preactjs.org"></a>
<a href="https://gatsbyjs.org"><img style="width:40px;height:40px" src="https://icon.horse/icon/gatsbyjs.org"></a>
<a href="https://svelte.dev"><img style="width:40px;height:40px" src="https://icon.horse/icon/svelte.dev"></a>
<a href="https://php.net"><img style="width:40px;height:40px" src="https://icon.horse/icon/php.net"></a>
<a href="https://laravel.com"><img style="width:40px;height:40px" src="https://icon.horse/icon/laravel.com"></a>
<a href="https://python.org"><img style="width:40px;height:40px" src="https://icon.horse/icon/python.org"></a>
<a href="https://deno.com"><img style="width:40px;height:40px" src="https://icon.horse/icon/deno.com"></a>
<a href="https://docker.com"><img style="width:40px;height:40px" src="https://icon.horse/icon/docker.com"></a>

### Databases

<a href="https://mongodb.org"><img style="width:40px;height:40px" src="https://icon.horse/icon/mongodb.org"></a>
<a href="https://mariadb.org"><img style="width:40px;height:40px" src="https://icon.horse/icon/mariadb.org"></a>
<a href="https://mysql.com"><svg style="width:40px;height:40px" xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 25.6 25.6" ><path d="M179.076 94.886c-3.568-.1-6.336.268-8.656 1.25-.668.27-1.74.27-1.828 1.116.357.355.4.936.713 1.428.535.893 1.473 2.096 2.32 2.72l2.855 2.053c1.74 1.07 3.703 1.695 5.398 2.766.982.625 1.963 1.428 2.945 2.098.5.357.803.938 1.428 1.16v-.135c-.312-.4-.402-.98-.713-1.428l-1.34-1.293c-1.293-1.74-2.9-3.258-4.64-4.506-1.428-.982-4.55-2.32-5.13-3.97l-.088-.1c.98-.1 2.14-.447 3.078-.715 1.518-.4 2.9-.312 4.46-.713l2.143-.625v-.4c-.803-.803-1.383-1.874-2.23-2.632-2.275-1.963-4.775-3.882-7.363-5.488-1.383-.892-3.168-1.473-4.64-2.23-.537-.268-1.428-.402-1.74-.848-.805-.98-1.25-2.275-1.83-3.436l-3.658-7.763c-.803-1.74-1.295-3.48-2.275-5.086-4.596-7.585-9.594-12.18-17.268-16.687-1.65-.937-3.613-1.34-5.7-1.83l-3.346-.18c-.715-.312-1.428-1.16-2.053-1.562-2.543-1.606-9.102-5.086-10.977-.5-1.205 2.9 1.785 5.755 2.8 7.228.76 1.026 1.74 2.186 2.277 3.346.3.758.4 1.562.713 2.365.713 1.963 1.383 4.15 2.32 5.98.5.937 1.025 1.92 1.65 2.767.357.5.982.714 1.115 1.517-.625.893-.668 2.23-1.025 3.347-1.607 5.042-.982 11.288 1.293 15 .715 1.115 2.4 3.57 4.686 2.632 2.008-.803 1.56-3.346 2.14-5.577.135-.535.045-.892.312-1.25v.1l1.83 3.703c1.383 2.186 3.793 4.462 5.8 5.98 1.07.803 1.918 2.187 3.256 2.677v-.135h-.088c-.268-.4-.67-.58-1.027-.892-.803-.803-1.695-1.785-2.32-2.677-1.873-2.498-3.523-5.265-4.996-8.12-.715-1.383-1.34-2.9-1.918-4.283-.27-.536-.27-1.34-.715-1.606-.67.98-1.65 1.83-2.143 3.034-.848 1.918-.936 4.283-1.248 6.737-.18.045-.1 0-.18.1-1.426-.356-1.918-1.83-2.453-3.078-1.338-3.168-1.562-8.254-.402-11.913.312-.937 1.652-3.882 1.117-4.774-.27-.848-1.16-1.338-1.652-2.008-.58-.848-1.203-1.918-1.605-2.855-1.07-2.5-1.605-5.265-2.766-7.764-.537-1.16-1.473-2.365-2.232-3.435-.848-1.205-1.783-2.053-2.453-3.48-.223-.5-.535-1.294-.178-1.83.088-.357.268-.5.623-.58.58-.5 2.232.134 2.812.4 1.65.67 3.033 1.294 4.416 2.23.625.446 1.295 1.294 2.098 1.518h.938c1.428.312 3.033.1 4.37.5 2.365.76 4.506 1.874 6.426 3.08 5.844 3.703 10.664 8.968 13.92 15.26.535 1.026.758 1.963 1.25 3.034.938 2.187 2.098 4.417 3.033 6.56.938 2.097 1.83 4.24 3.168 5.98.67.937 3.346 1.427 4.55 1.918.893.4 2.275.76 3.08 1.25 1.516.937 3.033 2.008 4.46 3.034.713.534 2.945 1.65 3.078 2.54zm-45.5-38.772a7.09 7.09 0 0 0-1.828.223v.1h.088c.357.714.982 1.205 1.428 1.83l1.027 2.142.088-.1c.625-.446.938-1.16.938-2.23-.268-.312-.312-.625-.535-.937-.268-.446-.848-.67-1.206-1.026z" transform="matrix(.390229 0 0 .38781 -46.300037 -16.856717)" fill-rule="evenodd" fill="#00678c" /></svg></a>
<a href="https://postgresql.org"><img style="width:40px;height:40px" src="https://icon.horse/icon/postgresql.org"></a>
<a href="https://couchdb.apache.org"><img style="width:40px;height:40px" src="https://icon.horse/icon/couchdb.apache.org"></a>
<a href="https://redis.io"><svg style="width:40px;height:40px" viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" ><defs ><path id="a" d="m45.536 38.764c-2.013 1.05-12.44 5.337-14.66 6.494s-3.453 1.146-5.207.308-12.85-5.32-14.85-6.276c-1-.478-1.524-.88-1.524-1.26v-3.813s14.447-3.145 16.78-3.982 3.14-.867 5.126-.14 13.853 2.868 15.814 3.587v3.76c0 .377-.452.8-1.477 1.324z" /><path id="b" d="m45.536 28.733c-2.013 1.05-12.44 5.337-14.66 6.494s-3.453 1.146-5.207.308-12.85-5.32-14.85-6.276-2.04-1.613-.077-2.382l15.332-5.935c2.332-.837 3.14-.867 5.126-.14s12.35 4.853 14.312 5.57 2.037 1.31.024 2.36z" /></defs ><g transform="matrix(.848327 0 0 .848327 -7.883573 -9.449691)" ><use fill="#a41e11" xlink:href="#a" /><path d="m45.536 34.95c-2.013 1.05-12.44 5.337-14.66 6.494s-3.453 1.146-5.207.308-12.85-5.32-14.85-6.276-2.04-1.613-.077-2.382l15.332-5.936c2.332-.836 3.14-.867 5.126-.14s12.35 4.852 14.31 5.582 2.037 1.31.024 2.36z" fill="#d82c20" /><use fill="#a41e11" xlink:href="#a" y="-6.218" /><use fill="#d82c20" xlink:href="#b" /><path d="m45.536 26.098c-2.013 1.05-12.44 5.337-14.66 6.495s-3.453 1.146-5.207.308-12.85-5.32-14.85-6.276c-1-.478-1.524-.88-1.524-1.26v-3.815s14.447-3.145 16.78-3.982 3.14-.867 5.126-.14 13.853 2.868 15.814 3.587v3.76c0 .377-.452.8-1.477 1.324z" fill="#a41e11" /><use fill="#d82c20" xlink:href="#b" y="-6.449" /><g fill="#fff" ><path d="m29.096 20.712-1.182-1.965-3.774-.34 2.816-1.016-.845-1.56 2.636 1.03 2.486-.814-.672 1.612 2.534.95-3.268.34zm-6.296 3.912 8.74-1.342-2.64 3.872z" /><ellipse cx="20.444" cy="21.402" rx="4.672" ry="1.811" /></g ><path d="m42.132 21.138-5.17 2.042-.004-4.087z" fill="#7a0c00" /><path d="m36.963 23.18-.56.22-5.166-2.042 5.723-2.264z" fill="#ad2115" /></g ></svg ></a>

### Services

- [Appwrite](https://appwrite.io)
- [WordPress](https://docs.coollabs.io/coolify/services/wordpress)
- [Ghost](https://ghost.org)
- [Plausible Analytics](https://docs.coollabs.io/coolify/services/plausible-analytics)
- [NocoDB](https://nocodb.com)
- [VSCode Server](https://github.com/cdr/code-server)
- [MinIO](https://min.io)
- [VaultWarden](https://github.com/dani-garcia/vaultwarden)
- [LanguageTool](https://languagetool.org)
- [n8n](https://n8n.io)
- [Uptime Kuma](https://github.com/louislam/uptime-kuma)
- [MeiliSearch](https://github.com/meilisearch/meilisearch)
- [Umami](https://github.com/mikecao/umami)
- [Fider](https://fider.io)
- [Hasura](https://hasura.io)
- [GlitchTip](https://glitchtip.com)
- And more...

## Support

- Mastodon: [@andrasbacsai@fosstodon.org](https://fosstodon.org/@andrasbacsai)
- Telegram: [@andrasbacsai](https://t.me/andrasbacsai)
- Twitter: [@andrasbacsai](https://twitter.com/andrasbacsai)
- Email: [andras@coollabs.io](mailto:andras@coollabs.io)
- Discord: [Invitation](https://coollabs.io/discord)

---

## ⚗️ Expertise Contributions

Coolify is developed under the [Apache License](./LICENSE) and you can help to make it grow.
Our community will be glad to have you on board!

Learn how to contribute to Coolify as as ...

&rarr; [👩🏾‍💻 Software developer](./CONTRIBUTION.md)

&rarr; [🧑🏻‍🏫 Translator](./docs/contribution/Translating.md)

<!-- 
&rarr; 🧑🏽‍🎨 Designer
&rarr; 🙋‍♀️ Community Managemer
&rarr; 🧙🏻‍♂️ Text Content Creator
&rarr; 👨🏼‍🎤 Video Content Creator
-->

---

## 💰 Financial Contributors

Become a financial contributor and help us sustain our community. [[Contribute](https://opencollective.com/coollabsio/contribute)]

### Individuals

<a href="https://opencollective.com/coollabsio"><img src="https://opencollective.com/coollabsio/individuals.svg?width=890"></a>

### Organizations

Support this project with your organization. Your logo will show up here with a link to your website. 

<a href="https://opencollective.com/coollabsio/organization/0/website"><img src="https://opencollective.com/coollabsio/organization/0/avatar.svg"></a>
<a href="https://opencollective.com/coollabsio/organization/1/website"><img src="https://opencollective.com/coollabsio/organization/1/avatar.svg"></a>
<a href="https://opencollective.com/coollabsio/organization/2/website"><img src="https://opencollective.com/coollabsio/organization/2/avatar.svg"></a>
<a href="https://opencollective.com/coollabsio/organization/3/website"><img src="https://opencollective.com/coollabsio/organization/3/avatar.svg"></a>
<a href="https://opencollective.com/coollabsio/organization/4/website"><img src="https://opencollective.com/coollabsio/organization/4/avatar.svg"></a>
<a href="https://opencollective.com/coollabsio/organization/5/website"><img src="https://opencollective.com/coollabsio/organization/5/avatar.svg"></a>
<a href="https://opencollective.com/coollabsio/organization/6/website"><img src="https://opencollective.com/coollabsio/organization/6/avatar.svg"></a>
<a href="https://opencollective.com/coollabsio/organization/7/website"><img src="https://opencollective.com/coollabsio/organization/7/avatar.svg"></a>
<a href="https://opencollective.com/coollabsio/organization/8/website"><img src="https://opencollective.com/coollabsio/organization/8/avatar.svg"></a>
<a href="https://opencollective.com/coollabsio/organization/9/website"><img src="https://opencollective.com/coollabsio/organization/9/avatar.svg"></a> 
