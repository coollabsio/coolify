/**
 * Deploy canvas: build a deploy workflow (project → server → source →
 * settings → deploy) from nodes, run it through Coolify's API controllers
 * (at /canvas/api, with the browser session) and follow the deployment logs.
 *
 * Mounted on the /canvas page into [data-deploy-canvas] by
 * canvas/deploy/index.js (Svelte components). This file keeps the DOM-free
 * parts: step types, flow rules and storage; `node --test` imports it.
 */

import { api } from './canvas-core.js';

// ── Settings ───────────────────────────────────────────────────────────
export const LOG_POLL_MS = 2000;       // Deployment log refresh (Coolify's own log view uses 2 s too).
export const STORE = 'coolify-canvas';  // localStorage prefix (flow, view).

// ── Small helpers ──────────────────────────────────────────────────────
// Icons: the app's reicon glyphs (rendered by the page into
// [data-deploy-canvas-icons]), or a few local paths in the same style.
export const LOCAL_ICONS = {
  minus: ['M5 12h14'],
  fit: ['M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5'],
  back: ['M19 12H5', 'M11 6l-6 6 6 6'], // the environment canvas back arrow
  fold: ['M5 4h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z', 'M9 4v16'], // the sidebar toggle in the navbar
};
export const uid = () => Math.random().toString(36).slice(2, 10);
const b64 = text => btoa(unescape(encodeURIComponent(text)));
export const load = (key, fallback) => {
  try { return JSON.parse(localStorage.getItem(`${STORE}:${key}`)) ?? fallback; } catch { return fallback; }
};
export const save = (key, value) => localStorage.setItem(`${STORE}:${key}`, JSON.stringify(value));
export const sleep = ms => new Promise(r => setTimeout(r, ms));

export const ICONS = {
  start: 'projects',
  server: 'servers',
  git: 'sources',
  image: 'cloud',
  file: 'file-content',
  layers: 'layers',
  db: 'database',
  sliders: 'sliders',
  variables: 'variables',
  globe: 'globe',
  deploy: 'play-circle',
  x: 'x',
  plus: 'plus',
  minus: 'minus',
  fit: 'fit',
  out: 'external-link',
  fold: 'fold',
  check: 'check',
  chevron: 'chevron-down',
  info: 'info-circle',
};

// Cached option lists for the node fields (key → array; loading state).
// onChange runs when a list loads or fails (the canvas redraws its fields).
export const lists = { cache: {}, pending: {}, errors: {}, onChange: () => {} };
function options(key, fetcher) {
  const { cache, pending, errors } = lists;
  if (cache[key]) return cache[key];
  if (!pending[key]) {
    pending[key] = fetcher().then(list => {
      cache[key] = list;
      delete errors[key];
    }).catch(error => {
      errors[key] = error.message;
    }).finally(() => {
      delete pending[key];
      lists.onChange();
    });
  }
  return null;
}
// The cache key a select loads from (to show its error).
export function loaderKey(f, d) {
  const map = { project: 'projects', environment: `envs:${d.project}`, server: 'servers', destination: `dest:${d.server}`, ghApp: 'gh', ghRepo: `repos:${d.ghApp}`, ghBranch: `br:${d.ghApp}:${d.ghRepo}`, key: 'keys' };
  return map[f.key];
}
const LOADERS = {
  projects: () => options('projects', async () => (await api('GET', '/projects')).map(p => [p.uuid, p.name])),
  envs: project => project && options(`envs:${project}`, async () => (await api('GET', `/projects/${project}/environments`)).map(e => [e.uuid, e.name])),
  // Build-only servers cannot run resources (as on the New resource page).
  servers: () => options('servers', async () => (await api('GET', '/servers')).filter(s => s.settings?.server_role !== 'build').map(s => [s.uuid, `${s.name}${s.is_reachable === false ? ' (unreachable)' : ''}`])),
  destinations: server => server && options(`dest:${server}`, async () => (await api('GET', `/servers/${server}/destinations`)).map(d => [d.uuid, `${d.name} · ${d.network}`])),
  ghApps: () => options('gh', async () => (await api('GET', '/github-apps')).filter(a => !a.is_public && a.app_id).map(a => [`${a.id}|${a.uuid}`, a.name])),
  repos: app => app && options(`repos:${app}`, async () => (await api('GET', `/github-apps/${app.split('|')[0]}/repositories`)).repositories.map(r => [r.full_name, r.full_name])),
  branches: (app, repo) => app && repo && options(`br:${app}:${repo}`, async () => (await api('GET', `/github-apps/${app.split('|')[0]}/repositories/${repo}/branches`)).branches.map(b => [b.name, b.name])),
  keys: () => options('keys', async () => (await api('GET', '/security/keys')).map(k => [k.uuid, k.name])),
};

// ── Node types ─────────────────────────────────────────────────────────
// stage: start → infra → source → config → ship. kind: what a source creates.
// Select options are [value, label, badge?]; the badge shows next to the label.
const BUILD_PACKS = [['railpack', 'Railpack'], ['nixpacks', 'Nixpacks', 'Deprecated'], ['static', 'Static'], ['dockerfile', 'Dockerfile'], ['dockercompose', 'Docker Compose']];
// The PostgreSQL images of the New resource page (project/new/select.blade.php).
const POSTGRES_IMAGES = [
  ['postgres:18-alpine', 'PostgreSQL 18'],
  ['postgres:17-alpine', 'PostgreSQL 17'],
  ['postgres:16-alpine', 'PostgreSQL 16'],
  ['supabase/postgres:17.4.1.032', 'Supabase PostgreSQL'],
  ['postgis/postgis:17-3.5-alpine', 'PostGIS'],
  ['pgvector/pgvector:pg18', 'PGVector 18'],
  ['pgvector/pgvector:pg17', 'PGVector 17'],
];
const DATABASES = [['postgresql', 'PostgreSQL'], ['mysql', 'MySQL'], ['mariadb', 'MariaDB'], ['mongodb', 'MongoDB'], ['redis', 'Redis'], ['keydb', 'KeyDB'], ['dragonfly', 'Dragonfly'], ['clickhouse', 'ClickHouse']];
// One-click service templates ([key, name]), from the page (deploy-canvas.blade.php).
let serviceTemplates = null;
const templateOptions = () => {
  if (!serviceTemplates) {
    try { serviceTemplates = JSON.parse(document.querySelector('[data-deploy-canvas-services]')?.textContent || '[]'); } catch { serviceTemplates = []; }
  }
  return serviceTemplates;
};

// The logo of a source step: the one resource_logo() (bootstrap/helpers/services.php) and the
// New resource page show for what it creates. Service logos come from the page
// (deploy-canvas.blade.php). Null keeps the step's glyph.
// The page gives versioned URLs (resource_logo_url()), so a changed logo is not loaded from the cache.
let resourceLogos = null;
const resourceLogoUrl = name => {
  if (!resourceLogos) {
    try { resourceLogos = JSON.parse(globalThis.document?.querySelector('[data-deploy-canvas-resource-logos]')?.textContent || '{}'); } catch { resourceLogos = {}; }
  }
  return resourceLogos[name] ?? `/svgs/resources/${name}.svg`;
};
const resourceLogo = (name, hasDark = false) => ({
  src: resourceLogoUrl(name), dark: hasDark ? resourceLogoUrl(`${name}-dark`) : null, fallback: null, padded: false,
});
const DATABASE_LOGOS = { postgresql: 'postgres' };
let serviceLogos = null;
const serviceLogo = key => {
  if (!serviceLogos) {
    try { serviceLogos = JSON.parse(document.querySelector('[data-deploy-canvas-service-logos]')?.textContent || '{}'); } catch { serviceLogos = {}; }
  }
  const logo = serviceLogos[key];
  return logo ? { src: logo[0], dark: null, fallback: logo[1], padded: true } : null;
};
export function logoOf(n) {
  const d = n.data || {};
  switch (n.type) {
    case 'git':
      if (d.buildPack === 'dockerfile') return resourceLogo('dockerfile');
      if (d.buildPack === 'dockercompose') return resourceLogo('docker-compose');
      if (d.mode === 'github') return resourceLogo('github-app', true);
      return resourceLogo(d.mode === 'key' ? 'deploy-key' : 'public-repo');
    case 'image': return resourceLogo('docker-image');
    case 'dockerfile': return resourceLogo('dockerfile');
    case 'service': return d.mode === 'compose' ? resourceLogo('docker-compose') : (d.type ? serviceLogo(d.type) : null);
    case 'database': return d.engine ? resourceLogo(DATABASE_LOGOS[d.engine] ?? d.engine, ['keydb', 'dragonfly'].includes(d.engine)) : null;
    default: return null;
  }
}

export const TYPES = {
  start: {
    title: 'Start', sub: 'Which project and environment', stage: 'start', icon: ICONS.start,
    fields: [
      // `add`: a "New …" row at the end of the list creates one and selects it.
      { key: 'project', label: 'Project', kind: 'select', load: () => LOADERS.projects(), rerender: true, required: true,
        add: { label: 'New project', placeholder: 'Project name', list: () => 'projects', create: async name => (await api('POST', '/projects', { name })).uuid } },
      { key: 'environment', label: 'Environment', kind: 'select', load: d => LOADERS.envs(d.project), required: true, only: true,
        add: { label: 'New environment', placeholder: 'Environment name', list: d => `envs:${d.project}`, create: async (name, d) => (await api('POST', `/projects/${d.project}/environments`, { name })).uuid } },
    ],
    run: async (ctx, d) => {
      ctx.project = d.project;
      ctx.environment = d.environment;
      return 'Project and environment set.';
    },
  },
  server: {
    title: 'Server', sub: 'Where it runs', stage: 'infra', icon: ICONS.server,
    fields: [
      { key: 'server', label: 'Server', kind: 'select', load: () => LOADERS.servers(), rerender: true, required: true, only: true },
      { key: 'destination', label: 'Network', kind: 'select', load: d => LOADERS.destinations(d.server), optional: 'Default' },
    ],
    run: async (ctx, d) => {
      ctx.server = d.server;
      ctx.destination = d.destination || undefined;
      return 'Server picked.';
    },
  },
  git: {
    title: 'Git repository', sub: 'Build from source', stage: 'source', kind: 'application', icon: ICONS.git,
    fields: [
      { key: 'mode', label: 'Access', kind: 'segmented', options: [['public', 'Public'], ['github', 'GitHub app'], ['key', 'Deploy key']], rerender: true, value: 'public' },
      { key: 'repository', label: 'Repository URL', kind: 'text', placeholder: 'https://github.com/coollabsio/coolify-examples', show: d => d.mode !== 'github', required: true, secret: v => /^\w+:\/\/[^/]*@/.test(v) },
      { key: 'ghApp', label: 'GitHub app', kind: 'select', load: () => LOADERS.ghApps(), show: d => d.mode === 'github', rerender: true, required: true },
      { key: 'ghRepo', label: 'Repository', kind: 'select', load: d => LOADERS.repos(d.ghApp), show: d => d.mode === 'github', rerender: true, required: true },
      { key: 'key', label: 'Deploy key', kind: 'select', load: () => LOADERS.keys(), show: d => d.mode === 'key', required: true },
      { key: 'branch', label: 'Branch', kind: 'text', placeholder: 'main', value: 'main', show: d => d.mode !== 'github', required: true },
      { key: 'ghBranch', label: 'Branch', kind: 'select', load: d => LOADERS.branches(d.ghApp, d.ghRepo), show: d => d.mode === 'github', required: true },
      { key: 'buildPack', label: 'Build pack', kind: 'select', options: BUILD_PACKS, value: 'railpack', required: true },
      { key: 'ports', label: 'Port', kind: 'text', placeholder: '3000', value: '3000' },
      { key: 'name', label: 'Name', kind: 'text', placeholder: 'Optional' },
    ],
    run: async (ctx, d) => {
      ctx.applicationSource = true;
      const base = common(ctx, d);
      Object.assign(base, { build_pack: d.buildPack, ports_exposes: d.ports || undefined });
      let res;
      if (d.mode === 'github') {
        res = await api('POST', '/applications/private-github-app', { ...base, github_app_uuid: d.ghApp.split('|')[1], git_repository: d.ghRepo, git_branch: d.ghBranch });
      } else if (d.mode === 'key') {
        res = await api('POST', '/applications/private-deploy-key', { ...base, private_key_uuid: d.key, git_repository: d.repository, git_branch: d.branch });
      } else {
        res = await api('POST', '/applications/public', { ...base, git_repository: d.repository, git_branch: d.branch });
      }
      ctx.resource = { kind: 'application', sub: 'git', uuid: res.uuid };
      ctx.createdDomains = res.domains && domainList(res.domains);
      return 'Application created.';
    },
  },
  image: {
    title: 'Docker image', sub: 'Run a prebuilt image', stage: 'source', kind: 'application', icon: ICONS.image,
    fields: [
      { key: 'image', label: 'Image', kind: 'text', placeholder: 'nginx', required: true },
      { key: 'tag', label: 'Tag', kind: 'text', placeholder: 'latest, or the tag in the image' },
      { key: 'ports', label: 'Port', kind: 'text', placeholder: '80', value: '80' },
      { key: 'name', label: 'Name', kind: 'text', placeholder: 'Optional' },
    ],
    run: async (ctx, d) => {
      ctx.applicationSource = true;
      const { name, tag } = splitImage(d.image, d.tag);
      const res = await api('POST', '/applications/dockerimage', { ...common(ctx, d), docker_registry_image_name: name, docker_registry_image_tag: tag, ports_exposes: d.ports || undefined });
      ctx.resource = { kind: 'application', sub: 'image', uuid: res.uuid };
      ctx.createdDomains = res.domains && domainList(res.domains);
      return 'Application created.';
    },
  },
  dockerfile: {
    title: 'Dockerfile', sub: 'Build from a Dockerfile', stage: 'source', kind: 'application', icon: ICONS.file,
    fields: [
      { key: 'dockerfile', label: 'Dockerfile', kind: 'textarea', placeholder: 'FROM nginx:alpine\nCOPY . /usr/share/nginx/html', required: true },
      { key: 'ports', label: 'Port', kind: 'text', placeholder: '80', value: '80' },
      { key: 'name', label: 'Name', kind: 'text', placeholder: 'Optional' },
    ],
    run: async (ctx, d) => {
      ctx.applicationSource = true;
      const res = await api('POST', '/applications/dockerfile', { ...common(ctx, d), dockerfile: b64(d.dockerfile), ports_exposes: d.ports || undefined });
      ctx.resource = { kind: 'application', sub: 'dockerfile', uuid: res.uuid };
      ctx.createdDomains = res.domains && domainList(res.domains);
      return 'Application created.';
    },
  },
  service: {
    title: 'Service', sub: 'One-click template or compose', stage: 'source', kind: 'service', icon: ICONS.layers,
    fields: [
      { key: 'mode', label: 'From', kind: 'segmented', options: [['template', 'Template'], ['compose', 'Compose']], rerender: true, value: 'template' },
      { key: 'type', label: 'Template', kind: 'select', load: () => templateOptions(), show: d => d.mode !== 'compose', required: true },
      { key: 'compose', label: 'docker-compose.yml', kind: 'textarea', placeholder: 'services:\n  app:\n    image: nginx', show: d => d.mode === 'compose', required: true, secret: true },
      { key: 'name', label: 'Name', kind: 'text', placeholder: 'Optional' },
    ],
    run: async (ctx, d) => {
      const body = common(ctx, d);
      if (d.mode === 'compose') body.docker_compose_raw = b64(d.compose);
      else body.type = d.type.trim();
      const res = await api('POST', '/services', body);
      ctx.resource = { kind: 'service', uuid: res.uuid };
      return 'Service created.';
    },
  },
  database: {
    title: 'Database', sub: 'A managed database', stage: 'source', kind: 'database', icon: ICONS.db,
    fields: [
      // A new engine starts with its default image (PostgreSQL: PostgreSQL 18).
      { key: 'engine', label: 'Engine', kind: 'select', options: DATABASES, value: 'postgresql', required: true,
        after: d => { d.image = d.engine === 'postgresql' ? POSTGRES_IMAGES[0][0] : ''; } },
      { key: 'image', label: 'Image', kind: 'select', options: POSTGRES_IMAGES, value: POSTGRES_IMAGES[0][0], show: d => d.engine === 'postgresql', required: true },
      { key: 'image', label: 'Image', kind: 'text', placeholder: 'Default for the engine', show: d => d.engine !== 'postgresql' },
      { key: 'name', label: 'Name', kind: 'text', placeholder: 'Optional' },
    ],
    run: async (ctx, d) => {
      const body = common(ctx, d);
      if (d.image) body.image = d.image;
      const res = await api('POST', `/databases/${d.engine}`, body);
      ctx.resource = { kind: 'database', uuid: res.uuid };
      return 'Database created.';
    },
  },
  build: {
    title: 'Build settings', sub: 'Directories and commands', stage: 'config', icon: ICONS.sliders, for: ['git'],
    fields: [
      { key: 'base_directory', label: 'Base directory', kind: 'text', placeholder: '/' },
      { key: 'publish_directory', label: 'Publish directory', kind: 'text', placeholder: '/dist' },
      { key: 'install_command', label: 'Install command', kind: 'text', placeholder: 'Detected' },
      { key: 'build_command', label: 'Build command', kind: 'text', placeholder: 'Detected' },
      { key: 'start_command', label: 'Start command', kind: 'text', placeholder: 'Detected' },
    ],
    run: async (ctx, d) => {
      const body = Object.fromEntries(['base_directory', 'publish_directory', 'install_command', 'build_command', 'start_command'].filter(k => d[k]).map(k => [k, d[k]]));
      if (!Object.keys(body).length) return 'Nothing to change.';
      await api('PATCH', `/applications/${ctx.resource.uuid}`, body);
      return 'Build settings saved.';
    },
  },
  env: {
    title: 'Environment variables', sub: 'KEY=value, one per line', stage: 'config', icon: ICONS.variables, for: ['application', 'service'],
    fields: [
      { key: 'vars', label: 'Variables', kind: 'textarea', placeholder: 'NODE_ENV=production\nAPI_URL=https://api.example.com', required: true, mono: true, secret: true },
    ],
    run: async (ctx, d) => {
      const data = parseEnvVars(d.vars);
      if (!data.length) return 'No variables.';
      const kind = ctx.resource.kind === 'service' ? 'services' : 'applications';
      await api('PATCH', `/${kind}/${ctx.resource.uuid}/envs/bulk`, { data });
      return `${data.length} variable${data.length === 1 ? '' : 's'} saved.`;
    },
  },
  domain: {
    title: 'Domains', sub: 'Your own public addresses', stage: 'config', icon: ICONS.globe, for: ['git', 'image', 'dockerfile'],
    fields: [
      { key: 'domains', label: 'Domains', kind: 'textarea', placeholder: 'https://app.example.com', required: true, mono: true, rows: 2 },
      { key: 'note', kind: 'note', text: 'Without this step, Coolify makes an address from the server’s wildcard domain (or sslip.io).' },
    ],
    run: async (ctx, d) => {
      const domains = domainList(d.domains);
      if (ctx.createdDomains === domains) return `Set at creation: ${domains.replace(/,/g, ', ')}.`;
      const res = await api('PATCH', `/applications/${ctx.resource.uuid}`, { domains });
      return `Domains saved${res?.uuid ? '' : ''}: ${domains.replace(/,/g, ', ')}.`;
    },
  },
  deploy: {
    title: 'Deploy', sub: 'Save or deploy every resource', stage: 'ship', icon: ICONS.deploy,
    fields: [],
  },
};
// "nginx:alpine" → nginx + alpine; "ghcr.io:443/a/b@sha256:…" → digest tag
// (Coolify's "sha256-…" form). A tag in the image wins over the Tag field.
export function splitImage(image, tagField) {
  let name = String(image || '').trim();
  let tag = '';
  const at = name.indexOf('@sha256:');
  if (at >= 0) {
    tag = `sha256-${name.slice(at + 8)}`;
    name = name.slice(0, at);
  } else {
    const colon = name.lastIndexOf(':');
    if (colon > name.lastIndexOf('/')) {
      tag = name.slice(colon + 1);
      name = name.slice(0, colon);
    }
  }
  return { name, tag: tag || String(tagField || '').trim() || 'latest' };
}
// Fields common to every create call. Domains from a Domains step later in
// the chain go in at creation (with no generated domain), as Coolify's
// create endpoints accept them.
function common(ctx, d) {
  const body = { project_uuid: ctx.project, environment_uuid: ctx.environment, server_uuid: ctx.server, instant_deploy: false };
  if (ctx.destination) body.destination_uuid = ctx.destination;
  if (d.name) body.name = d.name;
  if (ctx.domains && ctx.applicationSource) {
    body.domains = ctx.domains;
    body.autogenerate_domain = false;
  }
  return body;
}
// "KEY=value" lines → [{ key, value }]; skips blanks and # comments, strips quotes.
export function parseEnvVars(text) {
  return String(text || '').split('\n').map(line => line.trim()).filter(line => line && !line.startsWith('#') && line.includes('=')).map(line => {
    const i = line.indexOf('=');
    return { key: line.slice(0, i).trim(), value: line.slice(i + 1).trim().replace(/^(['"])(.*)\1$/, '$2') };
  });
}
export const domainList = text => String(text || '').split(/[\n,]/).map(v => v.trim()).filter(Boolean).join(',');
export const PALETTE = [
  ['Infrastructure', ['server']],
  ['Source', ['git', 'image', 'dockerfile', 'service', 'database']],
  ['Configure', ['build', 'env', 'domain']],
];

// ── Flow state ─────────────────────────────────────────────────────────
// Every deploy needs a server, so a new canvas has one, already connected
// (id 'server': it cannot be removed, nor its connection to Start).
export const fresh = () => withDeploy({
  nodes: [{ id: 'start', type: 'start', x: 80, y: 160, data: {} }, { id: 'server', type: 'server', x: 456, y: 160, data: {} }],
  edges: [{ from: 'start', to: 'server' }],
});
// A select with `only: true` takes its option when it has exactly one.
export const onlyOption = (list, value) => (!value && list?.length === 1 ? list[0][0] : value);
// Every flow ends in one Deploy step. Resource lines reach it on their own, so
// edges into it (from older saved flows) are dropped.
export function withDeploy(f) {
  if (!f.nodes.some(n => n.type === 'deploy')) f.nodes.push({ id: 'deploy', type: 'deploy', x: 1240, y: 160, data: {} });
  const deployIds = new Set(f.nodes.filter(n => n.type === 'deploy').map(n => n.id));
  f.edges = f.edges.filter(e => !deployIds.has(e.to) && !deployIds.has(e.from));
  return f;
}
// The environment that opened the canvas (?project=…&environment=…, from the
// New resource page), or null. Both values must look like UUIDs.
export function targetFrom(search) {
  const params = new URLSearchParams(search);
  const project = params.get('project');
  const environment = params.get('environment');
  const valid = value => /^[A-Za-z0-9_-]+$/.test(value ?? '');
  return valid(project) && valid(environment) ? { project, environment } : null;
}
// The Start step targets that environment, also in a flow restored from an earlier visit.
export function withTarget(f, t) {
  const start = f.nodes.find(n => n.type === 'start');
  if (t && start) start.data = { project: t.project, environment: t.environment };
  return f;
}
// Secret fields (`secret: true`, or a test on the value) stay in memory only;
// they are never written to localStorage.
export function withoutSecrets(f) {
  const nodes = f.nodes.map(n => {
    const data = { ...n.data };
    for (const { key, secret } of TYPES[n.type]?.fields || []) {
      if (secret === true || (typeof secret === 'function' && data[key] && secret(data[key]))) delete data[key];
    }
    return { ...n, data };
  });
  return { ...f, nodes };
}
// The flow is a tree: Start → Servers → one line per resource (source →
// settings…), and every line ends in the one Deploy step.
export const nodeById = (id, f) => f.nodes.find(n => n.id === id);
export const outsOf = (id, f) => f.edges.filter(e => e.from === id).map(e => e.to);
export const nextOf = (id, f) => outsOf(id, f)[0];
export const prevOf = (id, f) => f.edges.find(e => e.to === id)?.from;
// Start and Server fan out; only Deploy takes more than one line in.
export const multiOut = n => ['start', 'infra'].includes(TYPES[n.type].stage);
// The source node above a node in its line (what the settings apply to).
export function sourceAbove(id, f) {
  const seen = new Set();
  for (let cur = id; cur && !seen.has(cur); cur = prevOf(cur, f)) {
    seen.add(cur);
    const n = nodeById(cur, f);
    if (n && TYPES[n.type].stage === 'source') return n;
  }
  return null;
}
// Can a `type` step come after `prev`? With `target`, also: the link makes no loop.
export function canFollow(prev, type, target, f) {
  const t = TYPES[type];
  const p = TYPES[prev.type];
  if (type === 'start' || type === 'deploy' || prev.type === 'deploy') return false;
  if (target) {
    const seen = new Set();
    for (let cur = prev.id; cur && !seen.has(cur); cur = prevOf(cur, f)) {
      if (cur === target.id) return false;
      seen.add(cur);
    }
  }
  if (p.stage === 'start') return type === 'server';
  if (p.stage === 'infra') return t.stage === 'source';
  const src = sourceAbove(prev.id, f);
  if (!src) return false;
  if (t.stage === 'ship') return true;
  if (t.stage !== 'config') return false;
  const sub = src.type === 'git' || src.type === 'image' || src.type === 'dockerfile' ? src.type : null;
  return t.for.includes(TYPES[src.type].kind) || (sub !== null && t.for.includes(sub));
}
// What a run does: each resource line under each Server under Start.
export function planOf(f) {
  const servers = outsOf('start', f).map(id => nodeById(id, f)).filter(n => n?.type === 'server');
  const lines = [];
  for (const server of servers) {
    for (const id of outsOf(server.id, f)) {
      const source = nodeById(id, f);
      if (!source || TYPES[source.type].stage !== 'source') continue;
      const settings = [];
      const seen = new Set([source.id]);
      for (let cur = nextOf(source.id, f); cur && !seen.has(cur); cur = nextOf(cur, f)) {
        seen.add(cur);
        const n = nodeById(cur, f);
        if (!n) break;
        settings.push(n);
      }
      lines.push({ server, source, settings, end: settings.at(-1) || source });
    }
  }
  return { start: nodeById('start', f), servers, lines, deploy: f.nodes.find(n => n.type === 'deploy') || null };
}
// Source id → label; same labels get a number ("Docker image · nginx #2").
export function uniqueLabels(sources) {
  const labels = sources.map(resourceLabel);
  const seen = {};
  return new Map(sources.map((src, i) => {
    const label = labels[i];
    if (labels.filter(l => l === label).length < 2) return [src.id, label];
    seen[label] = (seen[label] || 0) + 1;
    return [src.id, `${label} #${seen[label]}`];
  }));
}
// "Docker image · nginx": what a source step makes, for lists and logs.
export function resourceLabel(src) {
  const d = src.data;
  const detail = d.name || {
    git: d.mode === 'github' ? d.ghRepo : d.repository,
    image: d.image,
    service: d.mode === 'compose' ? 'compose' : d.type,
    database: d.engine,
  }[src.type];
  return detail ? `${TYPES[src.type].title} · ${detail}` : TYPES[src.type].title;
}
export function defaults(type) {
  const data = {};
  for (const f of TYPES[type].fields) if (f.value !== undefined) data[f.key] = f.value;
  return data;
}
// The Coolify page of a created resource (its deployment, while one runs).
export function resourceHref(ctx) {
  const base = `/project/${ctx.project}/environment/${ctx.environment}`;
  const kind = { application: 'application', service: 'service', database: 'database' }[ctx.resource.kind];
  return ctx.deployment && kind === 'application' ? `${base}/application/${ctx.resource.uuid}/deployment/${ctx.deployment}` : `${base}/${kind}/${ctx.resource.uuid}`;
}
