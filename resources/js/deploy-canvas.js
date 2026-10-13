/**
 * Deploy canvas: build a deploy workflow (project → server → source →
 * settings → deploy) from nodes, run it through Coolify's API controllers
 * (at /canvas/api, with the browser session) and follow the deployment logs.
 *
 * Mounted on the /canvas page into [data-deploy-canvas].
 */

import { api, h as el, icon as canvasIcon, freeSpot, MIN_ZOOM, mountOnPage, panZoom, svg } from './canvas-core.js';

// ── Settings ───────────────────────────────────────────────────────────
const LOG_POLL_MS = 2000;       // Deployment log refresh (Coolify's own log view uses 2 s too).
const STORE = 'coolify-canvas';  // localStorage prefix (flow, view).

// Look: islands on a backdrop. The colors are CSS variables on .cv-root (see
// CSS below), so a custom color scheme can replace them.
const ISLAND = 'var(--cv-island)';
const BACKDROP = 'var(--cv-backdrop)';
const RADIUS = '12px';
const BUTTON_RADIUS = '8px';
const TEXT = 'var(--cv-text)';
const SUBTLE = 'var(--cv-subtle)';
const LINE = 'var(--cv-line)';
const INPUT_BG = 'var(--cv-input-bg)';
const INPUT_LINE = 'var(--cv-input-line)';
const BRAND = 'var(--cv-brand)';

// ── Small helpers ──────────────────────────────────────────────────────
// Icons: the app's reicon glyphs (rendered by the page into
// [data-deploy-canvas-icons]), or a few local paths in the same style.
const LOCAL_ICONS = {
  minus: ['M5 12h14'],
  fit: ['M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5'],
  back: ['M19 12H5', 'M11 6l-6 6 6 6'], // the environment canvas back arrow
  fold: ['M5 4h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z', 'M9 4v16'], // the sidebar toggle in the navbar
};
const icon = (name, cls) => canvasIcon('[data-deploy-canvas-icons]', name, LOCAL_ICONS[name], cls);
const uid = () => Math.random().toString(36).slice(2, 10);
const b64 = text => btoa(unescape(encodeURIComponent(text)));
const load = (key, fallback) => {
  try { return JSON.parse(localStorage.getItem(`${STORE}:${key}`)) ?? fallback; } catch { return fallback; }
};
const save = (key, value) => localStorage.setItem(`${STORE}:${key}`, JSON.stringify(value));
const sleep = ms => new Promise(r => setTimeout(r, ms));

const ICONS = {
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
const cache = {};
const pending = {};
const errorsOf = {};
function options(key, fetcher) {
  if (cache[key]) return cache[key];
  if (!pending[key]) {
    pending[key] = fetcher().then(list => {
      cache[key] = list;
      delete errorsOf[key];
    }).catch(error => {
      errorsOf[key] = error.message;
    }).finally(() => {
      delete pending[key];
      refreshBodies();
    });
  }
  return null;
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

const TYPES = {
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
const PALETTE = [
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
let target = null;
let canAdd = false; // may create projects and environments (from the page)
let flow = fresh();
let view = load('view', { x: 40, y: 40, z: 1 });
let runState = {}; // node id -> { state: running|done|failed, message }
// The last successful run's addresses, shown as a pill after Deploy.
let result = load('result', null); // { after: node id, urls: [], href }
let running = false;
let saveTimer = 0;
const persist = () => {
  clearTimeout(saveTimer);
  saveTimer = setTimeout(() => { save('flow', withoutSecrets(flow)); save('view', view); }, 200);
};
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
const nodeById = (id, f = flow) => f.nodes.find(n => n.id === id);
const outsOf = (id, f = flow) => f.edges.filter(e => e.from === id).map(e => e.to);
const nextOf = (id, f = flow) => outsOf(id, f)[0];
const prevOf = (id, f = flow) => f.edges.find(e => e.to === id)?.from;
// Start and Server fan out; only Deploy takes more than one line in.
const multiOut = n => ['start', 'infra'].includes(TYPES[n.type].stage);
// The source node above a node in its line (what the settings apply to).
export function sourceAbove(id, f = flow) {
  const seen = new Set();
  for (let cur = id; cur && !seen.has(cur); cur = prevOf(cur, f)) {
    seen.add(cur);
    const n = nodeById(cur, f);
    if (n && TYPES[n.type].stage === 'source') return n;
  }
  return null;
}
// Can a `type` step come after `prev`? With `target`, also: the link makes no loop.
export function canFollow(prev, type, target = null, f = flow) {
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
export function planOf(f = flow) {
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
function resourceLabel(src) {
  const d = src.data;
  const detail = d.name || {
    git: d.mode === 'github' ? d.ghRepo : d.repository,
    image: d.image,
    service: d.mode === 'compose' ? 'compose' : d.type,
    database: d.engine,
  }[src.type];
  return detail ? `${TYPES[src.type].title} · ${detail}` : TYPES[src.type].title;
}
function defaults(type) {
  const data = {};
  for (const f of TYPES[type].fields) if (f.value !== undefined) data[f.key] = f.value;
  return data;
}

// ── Styles ─────────────────────────────────────────────────────────────
const CSS = `
  .cv-root {
    /* The look of the environment canvas (environment-canvas.js): the app's
       own color tokens (app.css), so custom color schemes apply too. */
    --cv-island: light-dark(#ffffff, var(--color-panel));
    --cv-backdrop: light-dark(#fafafa, var(--color-app));
    --cv-card: light-dark(#ffffff, var(--color-raised));
    --cv-popover: light-dark(#ffffff, var(--color-panel));
    --cv-text: light-dark(#171717, var(--color-fg));
    --cv-subtle: light-dark(#737373, var(--color-fg-faint));
    --cv-placeholder: light-dark(#a3a3a3, var(--color-fg-faint));
    /* The app's card border (app.css), as on the environment canvas cards. */
    --cv-line: var(--coollabs-hairline, light-dark(#e5e5e5, rgb(255 255 255 / 0.08)));
    --cv-input-bg: light-dark(#fafafa, rgb(255 255 255 / 0.04));
    --cv-input-line: light-dark(#e5e5e5, rgb(255 255 255 / 0.08));
    --cv-brand: light-dark(var(--color-coollabs), var(--color-warning));
    --cv-brand-text: var(--cv-brand);
    position: absolute; inset: 0; z-index: 10;
    display: grid; grid-template-columns: 15.5rem minmax(0, 1fr); grid-template-rows: auto minmax(0, 1fr);
    gap: 8px; padding: 8px;
    background: ${BACKDROP}; color: ${TEXT};
    color-scheme: light; font: 13px/1.4 var(--font-sans, ui-sans-serif, system-ui, sans-serif);
    -webkit-font-smoothing: antialiased;
  }
  .cv-root.cv-dark { color-scheme: dark; }
  .cv-root *, .cv-root *::before, .cv-root *::after { box-sizing: border-box; }
  /* Rounded boxes on the backdrop: the top bar and the step list. */
  .cv-island { border: 1px solid ${LINE}; border-radius: ${RADIUS}; background: ${ISLAND}; }
  .cv-root svg { width: 1rem; height: 1rem; flex: none; }
  .cv-root button:not(.button) { font: inherit; }

  /* Header island on small screens; on desktop the header docks into the app top bar. */
  .cv-top { grid-column: 1 / -1; display: flex; flex-wrap: wrap; align-items: center; gap: 0.75rem; padding: 0.625rem 0.75rem; }
  .cv-root.cv-docked { grid-template-rows: minmax(0, 1fr); }
  .cv-btn {
    display: inline-flex; align-items: center; gap: 0.4375rem; height: 2rem; padding: 0 0.75rem;
    border: 1px solid ${LINE}; border-radius: ${BUTTON_RADIUS}; background: ${INPUT_BG};
    color: ${TEXT}; font-weight: 500; font-size: 12.5px; cursor: pointer; text-decoration: none; white-space: nowrap;
    transition: border-color 150ms ease, background-color 150ms ease;
  }
  .cv-btn:hover { border-color: light-dark(rgb(0 0 0 / 0.16), rgb(255 255 255 / 0.16)); }
  .cv-btn svg { width: 0.875rem; height: 0.875rem; }
  .cv-btn.icon { width: 2rem; padding: 0; justify-content: center; }
  .cv-btn.ghost { border-color: transparent; background: transparent; }
  .cv-btn.ghost:hover { background: light-dark(rgb(0 0 0 / 0.04), rgb(255 255 255 / 0.05)); }

  /* Small-screen notice: only below 768px, until it is closed. */
  .cv-notice { display: none; }
  @media (max-width: 767px) {
    .cv-root:has(> .cv-notice) { grid-template-rows: auto auto minmax(0, 1fr); }
    .cv-notice { grid-column: 1 / -1; display: flex; align-items: flex-start; gap: 0.5rem; padding: 0.625rem 0.5rem 0.625rem 0.75rem; background: #dc2626; color: #fff; font-size: 12.5px; line-height: 1.125rem; }
    .cv-notice > svg { margin-top: 0.0625rem; }
    .cv-notice p { flex: 1; min-width: 0; margin: 0; }
    .cv-notice a { color: inherit; font-weight: 600; text-decoration: underline; text-underline-offset: 2px; }
    .cv-notice .cv-x { opacity: 1; margin-top: -0.1875rem; color: inherit; }
    .cv-notice .cv-x:hover { background: rgb(255 255 255 / 0.18); color: inherit; }
  }

  /* Palette island */
  /* Hugs its steps instead of filling the row; scrolls when the screen is short. */
  .cv-palette { display: flex; flex-direction: column; align-self: start; max-height: 100%; overflow-y: auto; padding: 1rem 0.625rem 0.75rem; }
  .cv-palette-head { padding: 0 0.5rem 0.75rem; margin: 0 -0.625rem 0.5rem; padding-inline: 1.125rem; border-bottom: 1px solid ${LINE}; }
  .cv-palette-head b { display: block; font-size: 14px; font-weight: 600; }
  .cv-palette-head span { color: ${SUBTLE}; font-size: 12px; }
  .cv-palette h4 { margin: 0.75rem 0.5rem 0.25rem; color: ${SUBTLE}; font-size: 11px; font-weight: 500; }
  .cv-item {
    display: flex; align-items: center; gap: 0.625rem; width: 100%; min-height: 2.5rem; padding: 0.25rem 0.5rem 0.25rem 0.375rem;
    border: 0; border-radius: 9px; background: none; color: ${TEXT}; text-align: left;
    cursor: grab; user-select: none; touch-action: none;
  }
  /* Icon tiles: the neutral 32px tile of Coolify's collection cards. */
  .cv-item i, .cv-node-head i { display: grid; flex: none; place-items: center; width: 2rem; height: 2rem; border: 1px solid ${LINE}; border-radius: 8px; background: light-dark(#fafafa, rgb(255 255 255 / 0.04)); color: light-dark(#737373, var(--color-fg-dim, #c4c4c4)); }
  .cv-item i svg { width: 1rem; height: 1rem; }
  /* Palette tiles use the app's 3D button depth (app.css .button): a 2px ledge,
     a lift on hover, and a press on click. */
  /* Only the tile reacts; the row itself has no hover background. */
  .cv-item i { --cv-depth: light-dark(rgb(0 0 0 / 0.22), rgb(255 255 255 / 0.08)); box-shadow: 0 2px 0 var(--cv-depth); transition: border-color 120ms, background-color 120ms, box-shadow 80ms, transform 80ms; }
  .cv-item:hover i { transform: translateY(-1px); box-shadow: 0 3px 0 var(--cv-depth); border-color: light-dark(rgb(0 0 0 / 0.18), rgb(255 255 255 / 0.18)); background: light-dark(#fff, rgb(255 255 255 / 0.08)); }
  .cv-item:focus-visible { outline: none; }
  .cv-item:focus-visible i { outline: 1px solid var(--color-accent, currentColor); outline-offset: 2px; }
  .cv-item:active i { transform: translateY(2px); box-shadow: none; }
  .cv-item b { display: block; font-size: 13px; font-weight: 500; }
  .cv-item small { display: block; color: ${SUBTLE}; font-size: 11.5px; }

  /* Canvas island */
  .cv-stage { position: relative; overflow: hidden; border: 0; background: transparent; }
  .cv-viewport {
    position: absolute; inset: 0; cursor: grab; touch-action: none;
    background-image: radial-gradient(circle, light-dark(rgb(0 0 0 / 0.13), rgb(255 255 255 / 0.09)) 1px, transparent 1.3px);
  }
  .cv-viewport.panning { cursor: grabbing; }
  .cv-world { position: absolute; top: 0; left: 0; transform-origin: 0 0; }
  .cv-edges { position: absolute; top: 0; left: 0; width: 1px; height: 1px; overflow: visible; pointer-events: none; }
  .cv-edges path { fill: none; stroke: light-dark(#a3a3a3, rgb(255 255 255 / 0.35)); stroke-width: 1.75; }
  .cv-edges path.hit { stroke: transparent; stroke-width: 14; pointer-events: stroke; cursor: pointer; }
  .cv-edges path.hit:hover + path, .cv-edges path.live { stroke: color-mix(in srgb, ${BRAND} 70%, transparent); }
  /* The line being dragged: moving dashes, like the environment canvas (.ec-temp). */
  .cv-edges path.temp { stroke: ${BRAND}; stroke-width: 2; stroke-linecap: round; stroke-dasharray: 6 6; animation: cv-flow 0.6s linear infinite; }
  .cv-edges path.flow { stroke: ${BRAND}; stroke-dasharray: 6 6; animation: cv-flow 0.8s linear infinite; }
  @keyframes cv-flow { to { stroke-dashoffset: -12; } }

  /* Nodes: a card like ux-polish's flow boxes — badge + title + subtitle, then fields. */
  .cv-node {
    position: absolute; width: 18.5rem;
    border: 1px solid ${LINE}; border-radius: 12px;
    background: var(--cv-card);
    box-shadow: 0 1px 3px rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1);
    transition: box-shadow 150ms ease, border-color 150ms ease;
  }
  .cv-node:hover { border-color: light-dark(#d4d4d4, rgb(255 255 255 / 0.16)); box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1); }
  .cv-node.start { width: 16.5rem; }
  .cv-node.running { border-color: ${BRAND}; box-shadow: 0 0 0 4px color-mix(in srgb, ${BRAND} 16%, transparent); }
  .cv-node.done { border-color: color-mix(in srgb, #10b981 70%, transparent); }
  .cv-node.failed, .cv-node.invalid { border-color: color-mix(in srgb, #ef4444 75%, transparent); box-shadow: 0 0 0 4px color-mix(in srgb, #ef4444 12%, transparent); }
  .cv-node.drop-ok { border-color: ${BRAND}; box-shadow: 0 0 0 4px color-mix(in srgb, ${BRAND} 16%, transparent); }
  .cv-root { user-select: none; }
  .cv-root :is(input, textarea, .cv-logs) { user-select: text; }
  .cv-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; }
  .cv-deploy-now { width: 100%; }
  .cv-node-head { display: flex; align-items: center; gap: 0.625rem; height: 3.25rem; padding: 0 0.5rem 0 0.75rem; cursor: grab; user-select: none; }
  .cv-node-head i svg { width: 1rem; height: 1rem; }
  .cv-node-head div { display: grid; min-width: 0; margin-right: auto; }
  .cv-node-head b { overflow: hidden; font-size: 13.5px; font-weight: 600; line-height: 1.125rem; text-overflow: ellipsis; white-space: nowrap; }
  .cv-node-head small { overflow: hidden; color: ${SUBTLE}; font-size: 12px; line-height: 1rem; text-overflow: ellipsis; white-space: nowrap; }
  .cv-x { display: grid; place-items: center; width: 1.625rem; height: 1.625rem; padding: 0; border: 0; border-radius: 7px; background: none; color: ${SUBTLE}; cursor: pointer; opacity: 0; transition: opacity 120ms ease; }
  .cv-node:hover .cv-x, .cv-run .cv-x { opacity: 1; }
  .cv-x:hover { background: light-dark(rgb(0 0 0 / 0.06), rgb(255 255 255 / 0.08)); color: ${TEXT}; }
  .cv-x svg { width: 0.8125rem; height: 0.8125rem; }
  .cv-node-body { display: grid; gap: 0.75rem; padding: 0.875rem 0.875rem 0.875rem; border-top: 1px solid ${LINE}; }
  .cv-field { display: grid; gap: 0.375rem; min-width: 0; }
  .cv-field > label { color: ${SUBTLE}; font-size: 12px; font-weight: 500; }
  .cv-field > label em { color: var(--cv-brand-text); font-style: normal; }
  .cv-root .cv-input {
    width: 100%; height: 2rem; margin: 0; padding: 0 0.625rem; border: 1px solid ${INPUT_LINE}; border-radius: 8px;
    background: ${INPUT_BG}; color: ${TEXT}; font: inherit; font-size: 12.5px; outline: none; box-shadow: none;
  }
  .cv-root .cv-input::placeholder { color: var(--cv-placeholder); }
  .cv-root .cv-input:focus, .cv-root .cv-input:focus-visible {
    border-color: color-mix(in srgb, ${BRAND} 60%, transparent) !important;
    outline: none !important;
    box-shadow: 0 0 0 3px color-mix(in srgb, ${BRAND} 14%, transparent) !important;
  }
  .cv-root select.cv-input { padding-right: 1.75rem; appearance: none; background-image: url("data:image/svg+xml,${encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#8a8a8a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>')}"); background-repeat: no-repeat; background-position: right 0.5rem center; background-size: 0.875rem; }
  .cv-root textarea.cv-input { height: auto; min-height: 4.25rem; padding: 0.4375rem 0.625rem; line-height: 1.3rem; resize: vertical; }
  .cv-root .cv-input.mono, .cv-root textarea.cv-input { font-family: var(--font-mono, ui-monospace, SFMono-Regular, Menlo, monospace); font-size: 12px; }
  .cv-seg { display: flex; gap: 2px; padding: 2px; border-radius: 8px; background: light-dark(#f4f4f5, rgb(255 255 255 / 0.05)); }
  .cv-seg button { flex: 1; height: 1.625rem; padding: 0 0.5rem; border: 0; border-radius: 6px; background: none; color: ${SUBTLE}; font-size: 12px; font-weight: 500; cursor: pointer; }
  .cv-seg button.on { background: light-dark(#fff, rgb(255 255 255 / 0.09)); color: ${TEXT}; box-shadow: 0 1px 2px rgb(0 0 0 / 0.08), 0 0 0 1px ${LINE}; }
  .cv-note { margin: 0; color: ${SUBTLE}; font-size: 11.5px; line-height: 1.4; }
  .cv-note.bad { color: light-dark(#dc2626, #f87171); }
  .cv-status { display: flex; gap: 0.5rem; align-items: flex-start; padding: 0.625rem 0.875rem; border-top: 1px solid ${LINE}; font-size: 12px; line-height: 1.35; overflow-wrap: anywhere; }
  .cv-status::before { content: ''; flex: none; width: 0.5rem; height: 0.5rem; margin-top: 0.25rem; border-radius: 50%; background: var(--st); }
  .cv-status.running { --st: ${BRAND}; }
  .cv-status.done { --st: #10b981; }
  .cv-status.failed { --st: #ef4444; color: light-dark(#dc2626, #f87171); }

  /* Ports: filled dots on the header line, like the environment canvas. */
  .cv-port {
    position: absolute; top: calc(1.625rem - 0.375rem); width: 0.75rem; height: 0.75rem; border-radius: 50%;
    border: 2px solid var(--cv-card); background: light-dark(#a3a3a3, #737373); z-index: 2;
    box-shadow: 0 1px 3px rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1);
    transition: transform 120ms ease, background-color 120ms ease;
  }
  .cv-port.out { right: -0.375rem; cursor: crosshair; }
  .cv-port.in { left: -0.375rem; }
  .cv-port.out:hover, .cv-port.out.armed { background: ${BRAND}; transform: scale(1.2); }

  /* Collapsed steps: icons only. */
  .cv-root { transition: grid-template-columns 260ms cubic-bezier(0.4, 0, 0.2, 1); }
  .cv-root.cv-folded { grid-template-columns: 3.75rem minmax(0, 1fr); }
  .cv-palette-head { display: flex; align-items: flex-start; gap: 0.5rem; }
  .cv-palette-head > div { min-width: 0; margin-right: auto; }
  .cv-fold { opacity: 1; margin-top: 0.0625rem; }
  .cv-fold svg { width: 1rem; height: 1rem; }
  .cv-folded .cv-palette { padding-inline: 0.5rem; overflow-x: hidden; }
  .cv-folded .cv-palette-head { justify-content: center; margin-inline: -0.5rem; padding-inline: 0; }
  /* Only the step list folds; the "Add the next step" menu keeps its names. */
  .cv-folded .cv-palette-head > div, .cv-folded .cv-palette .cv-item > span { display: none; }
  .cv-folded .cv-fold svg { transform: scaleX(-1); }
  .cv-folded .cv-palette h4 { height: 1px; margin: 0.5rem 0.375rem; overflow: hidden; color: transparent; background: ${LINE}; }
  .cv-folded .cv-palette .cv-item { justify-content: center; padding: 0.25rem; }

  /* Our dropdown */
  .cv-root .cv-select { display: flex; align-items: center; gap: 0.5rem; text-align: left; cursor: pointer; }
  .cv-root .cv-select > span { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .cv-root .cv-select.empty > span { color: var(--cv-placeholder); }
  .cv-root .cv-select > svg { width: 0.875rem; height: 0.875rem; color: ${SUBTLE}; transition: transform 180ms ease; }
  .cv-root .cv-select.open > svg { transform: rotate(180deg); }
  .cv-root .cv-select.open { border-color: color-mix(in srgb, ${BRAND} 60%, transparent); box-shadow: 0 0 0 3px color-mix(in srgb, ${BRAND} 14%, transparent); }
  .cv-root .cv-select:disabled { cursor: default; opacity: 0.7; }
  .cv-dd {
    position: fixed; z-index: 60; display: flex; flex-direction: column; max-height: 17rem; padding: 0.3125rem;
    border: 1px solid ${LINE}; border-radius: 10px; background: var(--cv-popover);
    box-shadow: 0 16px 40px -14px rgb(0 0 0 / 0.4); animation: cv-dd-in 120ms ease-out;
  }
  @keyframes cv-dd-in { from { opacity: 0; transform: translateY(-3px); } }
  .cv-dd-search {
    flex: none; height: 1.875rem; margin-bottom: 0.25rem; padding: 0 0.625rem; border: 1px solid ${INPUT_LINE}; border-radius: 7px;
    background: ${INPUT_BG}; color: ${TEXT}; font: inherit; font-size: 12.5px; outline: none;
  }
  .cv-dd-search:focus { border-color: color-mix(in srgb, ${BRAND} 60%, transparent); }
  .cv-dd-list { overflow-x: hidden; overflow-y: auto; display: grid; grid-template-columns: minmax(0, 1fr); gap: 1px; }
  .cv-dd-opt {
    display: flex; align-items: center; gap: 0.5rem; width: 100%; min-height: 2rem; padding: 0 0.5rem 0 0.625rem;
    border: 0; border-radius: 7px; background: none; color: ${TEXT}; font-size: 12.5px; text-align: left; cursor: pointer;
  }
  .cv-dd-opt > span { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .cv-dd-opt > svg { width: 0.875rem; height: 0.875rem; color: var(--cv-brand-text); opacity: 0; }
  .cv-dd-opt:focus, .cv-dd-opt:focus-visible { outline: none; box-shadow: none; }
  .cv-dd-opt.on { font-weight: 600; }
  .cv-badge { flex: none; padding: 0.0625rem 0.4375rem; border: 1px solid light-dark(rgb(217 119 6 / 0.3), rgb(251 191 36 / 0.3)); border-radius: 999px; background: light-dark(rgb(217 119 6 / 0.1), rgb(251 191 36 / 0.12)); color: light-dark(#b45309, #fbbf24); font-size: 11px; font-style: normal; font-weight: 500; line-height: 1.4; }
  .cv-dd-opt.on > svg { opacity: 1; }
  .cv-dd-opt.active { background: light-dark(rgb(0 0 0 / 0.045), rgb(255 255 255 / 0.06)); }
  .cv-dd-none { margin: 0; padding: 0.5rem 0.625rem; color: ${SUBTLE}; font-size: 12px; }
  .cv-dd-add { flex: none; margin-top: 0.25rem; padding-top: 0.25rem; border-top: 1px solid ${LINE}; }
  .cv-dd-opt.cv-dd-new { color: var(--cv-brand-text); font-weight: 500; }
  .cv-dd-opt.cv-dd-new > svg { opacity: 1; color: inherit; }
  .cv-dd-form { display: flex; gap: 0.375rem; }
  .cv-dd-form .cv-dd-search { flex: 1; min-width: 0; margin: 0; }
  .cv-dd-form .button { height: 1.875rem; }
  .cv-dd-add .cv-note:not(:empty) { padding: 0.375rem 0.25rem 0.125rem; }

  /* Result pill after Deploy: the live address(es). */
  .cv-result {
    position: absolute; display: flex; align-items: center; gap: 0.25rem; height: 2.375rem; padding: 0 0.25rem 0 0.875rem;
    border: 1px solid color-mix(in srgb, #10b981 45%, transparent); border-radius: 999px;
    background: var(--cv-card); box-shadow: 0 8px 24px -12px rgb(0 0 0 / 0.25); white-space: nowrap;
  }
  .cv-result > i { width: 0.5rem; height: 0.5rem; margin-right: 0.25rem; border-radius: 50%; background: #10b981; box-shadow: 0 0 0 4px color-mix(in srgb, #10b981 18%, transparent); }
  .cv-result a { display: inline-flex; align-items: center; gap: 0.375rem; height: 1.75rem; padding: 0 0.625rem; border-radius: 999px; color: ${TEXT}; font-weight: 600; text-decoration: none; }
  .cv-result a:hover { background: light-dark(rgb(0 0 0 / 0.05), rgb(255 255 255 / 0.07)); }
  .cv-result a svg { width: 0.8125rem; height: 0.8125rem; color: ${SUBTLE}; }
  .cv-result .cv-x { opacity: 1; }
  .cv-result .cv-port.in { top: calc(50% - 0.375rem); }

  /* Next-step menu, toasts */
  .cv-menu { position: absolute; z-index: 20; width: 15rem; padding: 0.375rem; border: 1px solid ${LINE}; border-radius: 12px; background: ${ISLAND}; box-shadow: 0 16px 40px -16px rgb(0 0 0 / 0.4); }
  .cv-menu h5 { margin: 0.25rem 0.5rem 0.375rem; color: ${SUBTLE}; font-size: 11.5px; font-weight: 500; }
  .cv-menu .cv-item { cursor: pointer; }
  .cv-toast { position: absolute; left: 50%; bottom: 1rem; z-index: 30; max-width: 80%; transform: translateX(-50%); padding: 0.5rem 0.875rem; border: 1px solid ${LINE}; border-radius: 8px; background: ${ISLAND}; color: ${TEXT}; font-size: 12px; pointer-events: none; box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1); }
  .cv-toast.bad { border-color: light-dark(#fecaca, rgb(239 68 68 / 0.3)); color: light-dark(#b91c1c, #fca5a5); }
  /* Blank canvas: the Start ring pulses and a dashed trail shows where to drag it. */
  .cv-node.blank .cv-port.out { background: ${BRAND}; }
  .cv-node.blank .cv-port.out::after { content: ''; position: absolute; inset: -2px; border-radius: 50%; border: 2px solid ${BRAND}; animation: cv-breathe 1.8s ease-out infinite; pointer-events: none; }
  .cv-hint { position: absolute; left: calc(100% + 0.625rem); top: 1.625rem; transform: translateY(-50%); display: flex; align-items: center; gap: 0.5rem; color: var(--cv-brand-text); font-size: 12px; font-weight: 500; white-space: nowrap; pointer-events: none; }
  .cv-hint::before { content: ''; width: 4.5rem; border-top: 2px dashed currentColor; opacity: 0.7; animation: cv-hint-grow 1.8s ease-in-out infinite; transform-origin: left; }
  .cv-hint span { animation: cv-hint-fade 1.8s ease-in-out infinite; }
  .cv-node:has(.cv-port.armed) .cv-hint, .cv-node:has(.cv-port.armed) .cv-port.out::after { display: none; }
  @keyframes cv-hint-grow { 0% { transform: scaleX(0); } 60%, 100% { transform: scaleX(1); } }
  @keyframes cv-hint-fade { 0%, 30% { opacity: 0; } 60%, 100% { opacity: 1; } }
  @media (prefers-reduced-motion: reduce) { .cv-node.blank .cv-port.out::after, .cv-hint::before, .cv-hint span { animation: none; } }

  /* Run panel: an island with the steps as status lights, then the logs. */
  .cv-run { position: absolute; top: 0.75rem; right: 0.75rem; bottom: 0.75rem; z-index: 10; display: flex; flex-direction: column; width: min(26rem, 45%); overflow: hidden; border: 1px solid ${LINE}; border-radius: ${RADIUS}; background: ${ISLAND}; box-shadow: 0 20px 48px -20px rgb(0 0 0 / 0.4); }
  .cv-run[hidden] { display: none; }
  .cv-run header { display: flex; align-items: flex-start; gap: 0.5rem; padding: 1rem 0.75rem 0.875rem 1.25rem; border-bottom: 1px solid ${LINE}; }
  .cv-run header div { display: grid; gap: 0.0625rem; margin-right: auto; }
  .cv-run header b { font-size: 14px; font-weight: 600; }
  .cv-run header span { color: ${SUBTLE}; font-size: 12.5px; }
  .cv-steps { flex: none; max-height: 40%; overflow-y: auto; display: grid; gap: 0.25rem; padding: 0.75rem 1rem 0.875rem; border-bottom: 1px solid ${LINE}; }
  .cv-step { display: grid; grid-template-columns: 1.25rem minmax(0, 1fr); align-items: start; font-size: 12.5px; line-height: 1.25rem; }
  .cv-step i { position: relative; width: 0.5rem; height: 0.5rem; margin: 0.375rem 0 0 0.125rem; border-radius: 50%; background: transparent; box-shadow: inset 0 0 0 1.5px light-dark(#c4c4c4, #4a4a4a); }
  .cv-step.running i { background: ${BRAND}; box-shadow: none; }
  .cv-step.running i::after { content: ''; position: absolute; inset: 0; border-radius: 50%; background: ${BRAND}; animation: cv-breathe 1.8s ease-out infinite; }
  @keyframes cv-breathe { 0% { transform: scale(1); opacity: 0.5; } 80%, 100% { transform: scale(2.6); opacity: 0; } }
  .cv-step.done i { background: #10b981; box-shadow: none; }
  .cv-step.failed i { background: #ef4444; box-shadow: none; }
  .cv-step small { display: block; color: ${SUBTLE}; font-size: 11.5px; line-height: 1rem; overflow-wrap: anywhere; }
  .cv-step.failed small { color: light-dark(#dc2626, #f87171); }
  .cv-tabs { flex: none; margin: 0.75rem 0.75rem 0; overflow-x: auto; scrollbar-width: none; }
  .cv-tabs[hidden] { display: none; }
  .cv-tabs button { flex: 0 1 auto; display: inline-flex; align-items: center; gap: 0.375rem; min-width: 0; max-width: 13rem; }
  .cv-tabs button span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .cv-tabs i { flex: none; width: 0.4375rem; height: 0.4375rem; border-radius: 50%; box-shadow: inset 0 0 0 1.5px light-dark(#c4c4c4, #4a4a4a); }
  .cv-tabs button:first-child { flex: none; }
  .cv-tabs button:first-child i { display: none; }
  .cv-tabs i.running { background: ${BRAND}; box-shadow: none; animation: cv-blink 1.2s ease-in-out infinite; }
  .cv-tabs i.done { background: #10b981; box-shadow: none; }
  .cv-tabs i.failed { background: #ef4444; box-shadow: none; }
  @keyframes cv-blink { 50% { opacity: 0.35; } }
  .cv-logs-wrap { flex: 1; min-height: 0; padding: 0.75rem; display: flex; }
  .cv-logs[hidden] { display: none; }
  .cv-logs { flex: 1; min-height: 0; margin: 0; padding: 0.75rem 0.875rem; overflow: auto; border: 1px solid ${INPUT_LINE}; border-radius: 10px; background: ${INPUT_BG}; font: 11.5px/1.6 var(--font-mono, ui-monospace, SFMono-Regular, Menlo, monospace); white-space: pre-wrap; overflow-wrap: anywhere; }
  .cv-logs:empty::before { content: 'Logs show here while it deploys.'; color: ${SUBTLE}; font-family: var(--font-sans, ui-sans-serif, system-ui, sans-serif); }
  .cv-logs .err { color: light-dark(#dc2626, #f87171); }
  .cv-logs .dim { color: ${SUBTLE}; }
  .cv-run footer { display: flex; justify-content: flex-end; gap: 0.5rem; padding: 0 0.75rem 0.75rem; }
  .cv-run footer a[hidden] { display: none; }

  /* Small screens: one column. The steps are a strip above the canvas; swipe it sideways,
     tap a step to add it to the chain, or drag it down onto the canvas. */
  @media (max-width: 767px) {
    .cv-root, .cv-root.cv-folded { grid-template-columns: minmax(0, 1fr); grid-template-rows: auto auto minmax(0, 1fr); }
    .cv-root:has(> .cv-notice) { grid-template-rows: auto auto auto minmax(0, 1fr); }
    .cv-root .cv-palette, .cv-root.cv-folded .cv-palette { flex-direction: row; align-items: center; gap: 0.25rem; align-self: stretch; max-height: none; padding: 0.375rem; overflow: auto hidden; }
    .cv-palette-head, .cv-palette h4, .cv-root.cv-folded .cv-palette h4 { display: none; }
    .cv-root .cv-palette .cv-item, .cv-root.cv-folded .cv-palette .cv-item { flex: none; width: auto; justify-content: flex-start; padding: 0.25rem 0.625rem 0.25rem 0.25rem; touch-action: pan-x; }
    .cv-root.cv-folded .cv-palette .cv-item > span { display: block; }
    .cv-item small { display: none; }
    .cv-run { left: 0.75rem; width: auto; }
  }

`;

// ── UI ─────────────────────────────────────────────────────────────────
let ui = null;
function build(container) {
  if (!document.getElementById('deploy-canvas-style')) {
    const style = el('style');
    style.id = 'deploy-canvas-style';
    style.textContent = CSS;
    document.head.append(style);
  }

  const root = el('div', 'cv-root');

  // Top bar (placed by placeTop)
  const top = el('div');
  const title = el('div', 'mr-auto flex min-w-0 items-center gap-2');
  title.append(
    el('b', 'shrink-0 text-[14px] font-semibold text-black dark:text-fg', 'Deploy canvas'),
    el('span', 'shrink-0 rounded-full border border-coollabs/30 bg-coollabs/10 px-2 py-0.5 text-xs font-medium text-coollabs dark:border-warning/30 dark:bg-warning/15 dark:text-warning', 'Beta'),
  );
  const reset = el('button', 'button', 'Reset');
  reset.type = 'button';
  reset.title = 'Start again with an empty canvas';
  // Opens the app's confirmation modal (deploy-canvas.blade.php), which
  // dispatches deploy-canvas-reset on confirm.
  reset.addEventListener('click', () => {
    if (!running) document.getElementById('deploy-canvas-reset-trigger')?.click();
  });
  // Zoom controls, as on the environment canvas header.
  const headButton = (ic, t, fn) => {
    const b = el('button', 'button px-2!');
    b.type = 'button';
    b.title = t;
    b.setAttribute('aria-label', t);
    b.append(icon(ic, 'size-4'));
    b.addEventListener('click', fn);
    return b;
  };
  const zoomOut = headButton(ICONS.minus, 'Zoom out', () => ui.view.zoomTo(view.z / 1.2));
  zoomOut.classList.add('rounded-r-none!');
  const zoomLabel = el('button', 'button rounded-none! border-x-0! w-14 tabular-nums', '100%');
  zoomLabel.type = 'button';
  zoomLabel.title = 'Reset zoom to 100%';
  zoomLabel.addEventListener('click', () => ui.view.zoomTo(1));
  const zoomIn = headButton(ICONS.plus, 'Zoom in', () => ui.view.zoomTo(view.z * 1.2));
  zoomIn.classList.add('rounded-l-none!');
  const zoomGroup = el('div', 'flex items-center');
  zoomGroup.append(zoomOut, zoomLabel, zoomIn);
  const fitButton = headButton(ICONS.fit, 'Fit to screen', fit);
  fitButton.append('Fit');
  const actions = el('div', 'flex shrink-0 items-center gap-2');
  actions.append(zoomGroup, fitButton, reset);
  top.append(title, actions);

  // Palette
  const palette = el('aside', 'cv-island cv-palette');
  const phead = el('div', 'cv-palette-head');
  const ptext = el('div');
  ptext.append(el('b', '', 'Steps'), el('span', '', 'Drag one onto the canvas'));
  const fold = el('button', 'cv-x cv-fold');
  fold.type = 'button';
  fold.append(icon(ICONS.fold));
  fold.addEventListener('click', () => {
    save('collapsed', !load('collapsed', false));
    applyCollapsed();
  });
  phead.append(ptext, fold);
  palette.append(phead);
  for (const [group, types] of PALETTE) {
    palette.append(el('h4', '', group));
    for (const type of types) palette.append(paletteItem(type));
  }

  // Stage
  const stage = el('section', 'cv-island cv-stage');
  const viewport = el('div', 'cv-viewport');
  const world = el('div', 'cv-world');
  const edges = svg('svg', { class: 'cv-edges' });
  world.append(edges);
  viewport.append(world);
  const run = buildRunPanel();
  stage.append(viewport, run.root);

  root.append(...[top, buildNotice(), palette, stage].filter(Boolean));
  container.replaceChildren(root);

  ui?.topbarObserver?.disconnect();
  ui = { root, top, stage, viewport, world, edges, zoomLabel, run, nodeEls: new Map() };
  placeTop();
  // The floating header is centered over the canvas next to the app top bar, so follow their sizes.
  ui.topbarObserver = new ResizeObserver(() => ui.root.isConnected && placeTop());
  for (const target of [stage, document.querySelector('.app-topbar')].filter(Boolean)) ui.topbarObserver.observe(target);
  bindCanvas();
}

const DESKTOP = '(min-width: 1024px)';
// Desktop: the header docks into the app top bar, or with the floating nav it is a
// separate panel next to the top bar (as on the environment canvas) when it fits there.
// Small screens: a box above the canvas.
function placeTop() {
  const desktop = matchMedia(DESKTOP).matches;
  const slot = desktop && document.getElementById('page-toolbar-slot');
  const topbar = document.querySelector('.app-topbar');
  ui.top.removeAttribute('style');
  if (desktop && topbar && document.documentElement.dataset.navStyle === 'floating') {
    ui.top.className = 'fixed z-40 flex items-center gap-3 rounded-xl border border-neutral-200 bg-white px-2 py-1.5 dark:border-white/[0.08] dark:bg-panel';
    ui.root.append(ui.top);
    const gap = 8;
    const head = topbar.getBoundingClientRect();
    const width = ui.top.offsetWidth;
    if (head.right + gap + width <= innerWidth - gap) {
      // Centered over the canvas, but never over the top bar.
      const stage = ui.stage.getBoundingClientRect();
      const left = Math.max(stage.left + (stage.width - width) / 2, head.right + gap);
      Object.assign(ui.top.style, { top: `${head.top}px`, left: `${left}px`, minHeight: `${head.height}px` });
      ui.root.classList.add('cv-docked');
      return;
    }
    ui.top.removeAttribute('style');
  }
  ui.top.className = slot ? 'flex items-center gap-3 pl-3' : 'cv-island cv-top';
  ui.root.classList.toggle('cv-docked', !!slot);
  if (slot) slot.replaceChildren(ui.top);
  else ui.root.prepend(ui.top);
}

function paletteItem(type, onPick) {
  const t = TYPES[type];
  const b = el('button', 'cv-item');
  b.type = 'button';
  const badge = el('i');
  badge.append(icon(t.icon));
  const text = el('span');
  text.append(el('b', '', t.title), el('small', '', t.sub));
  b.append(badge, text);
  b.title = `${t.title} — ${t.sub}`;
  if (onPick) {
    b.addEventListener('click', onPick);
    return b;
  }
  // Drag to the canvas, or click to append to the chain.
  b.addEventListener('pointerdown', event => {
    if (event.button !== 0) return;
    const startX = event.clientX;
    const startY = event.clientY;
    let ghost = null;
    const move = e => {
      if (!ghost && Math.hypot(e.clientX - startX, e.clientY - startY) < 5) return;
      if (!ghost) {
        ghost = b.cloneNode(true);
        Object.assign(ghost.style, { position: 'fixed', zIndex: 2147483001, width: `${b.offsetWidth}px`, pointerEvents: 'none', opacity: 0.9, background: 'var(--cv-ghost)', borderRadius: '8px', boxShadow: '0 8px 24px -8px rgb(0 0 0 / 0.35)' });
        ghost.style.setProperty('--cv-ghost', 'light-dark(#fff, #222)');
        ui.root.append(ghost);
      }
      ghost.style.left = `${e.clientX - 20}px`;
      ghost.style.top = `${e.clientY - 18}px`;
    };
    const stop = () => {
      removeEventListener('pointermove', move);
      removeEventListener('pointerup', up);
      removeEventListener('pointercancel', cancel);
    };
    // Touch: a sideways swipe scrolls the step strip (small screens) and cancels the pointer.
    const cancel = () => {
      stop();
      ghost?.remove();
    };
    const up = e => {
      stop();
      if (!ghost) return appendToChain(type);
      ghost.remove();
      const r = ui.viewport.getBoundingClientRect();
      if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) return;
      const p = toWorld(e.clientX, e.clientY);
      addNode(type, p.x - 30, p.y - 16);
    };
    addEventListener('pointermove', move);
    addEventListener('pointerup', up);
    addEventListener('pointercancel', cancel);
  });
  return b;
}

// ── Canvas: pan, zoom, connect ─────────────────────────────────────────
const applyView = () => ui.view.apply();
const toWorld = (cx, cy) => ui.view.toWorld(cx, cy);
function fit() {
  const els = [...ui.nodeEls.values()];
  if (!els.length) return;
  let minX = Infinity; let minY = Infinity; let maxX = -Infinity; let maxY = -Infinity;
  for (const n of flow.nodes) {
    const node = ui.nodeEls.get(n.id);
    minX = Math.min(minX, n.x);
    minY = Math.min(minY, n.y);
    maxX = Math.max(maxX, n.x + (node?.offsetWidth || 280));
    maxY = Math.max(maxY, n.y + (node?.offsetHeight || 120));
  }
  const pill = ui.world.querySelector('.cv-result');
  if (pill) {
    maxX = Math.max(maxX, pill.offsetLeft + pill.offsetWidth);
    maxY = Math.max(maxY, pill.offsetTop + pill.offsetHeight);
  }
  const r = ui.viewport.getBoundingClientRect();
  const pad = 60;
  view.z = Math.min(1.2, Math.max(MIN_ZOOM, Math.min((r.width - pad * 2) / (maxX - minX), (r.height - pad * 2) / (maxY - minY))));
  view.x = (r.width - (maxX - minX) * view.z) / 2 - minX * view.z;
  view.y = (r.height - (maxY - minY) * view.z) / 2 - minY * view.z;
  applyView();
}

function bindCanvas() {
  ui.view = panZoom({
    root: ui.root,
    viewport: ui.viewport,
    world: ui.world,
    zoomLabel: ui.zoomLabel,
    getView: () => view,
    onChange: () => {
      closeDropdown();
      persist();
    },
    canPan: e => [ui.viewport, ui.world, ui.edges].includes(e.target),
    onPanStart: closeMenu,
  });
}

// Port positions in world coordinates.
const PORT_Y = 26; // header centre (3.25rem / 2)
function outPoint(n) {
  const node = ui.nodeEls.get(n.id);
  const w = node ? node.offsetWidth : 296;
  return { x: n.x + w, y: n.y + PORT_Y };
}
const inPoint = n => ({ x: n.x, y: n.y + PORT_Y });
const curve = (a, b) => {
  const dx = Math.max(40, Math.abs(b.x - a.x) / 2);
  return `M ${a.x} ${a.y} C ${a.x + dx} ${a.y}, ${b.x - dx} ${b.y}, ${b.x} ${b.y}`;
};
function renderEdges(temp) {
  const layer = ui.edges;
  layer.replaceChildren();
  for (const edge of flow.edges) {
    const a = nodeById(edge.from);
    const b = nodeById(edge.to);
    if (!a || !b) continue;
    const d = curve(outPoint(a), inPoint(b));
    const hit = svg('path', { class: 'hit', d });
    hit.addEventListener('click', () => {
      if (running) return;
      flow.edges = flow.edges.filter(e => e !== edge);
      persist();
      renderAll();
      toast('Connection removed.');
    });
    const title = svg('title');
    title.textContent = 'Click to remove this connection';
    hit.append(title);
    const line = svg('path', { d });
    const st = runState[edge.to]?.state;
    if (st === 'running') line.setAttribute('class', 'flow');
    else if (runState[edge.from]?.state === 'done' && st) line.setAttribute('class', 'live');
    if (edge.from === 'start' && edge.to === 'server') layer.append(line);
    else layer.append(hit, line);
  }
  const plan = planOf();
  for (const { end } of plan.lines) {
    const line = svg('path', { d: curve(outPoint(end), inPoint(plan.deploy)) });
    const st = runState[plan.deploy.id]?.state;
    if (st === 'running') line.setAttribute('class', 'flow');
    else if (st === 'done') line.setAttribute('class', 'live');
    layer.append(line);
  }
  const after = result && nodeById(result.after);
  if (after) {
    const a = outPoint(after);
    const line = svg('path', { class: 'live', d: curve(a, { x: a.x + 72, y: a.y }) });
    layer.append(line);
    const pill = ui.world.querySelector('.cv-result');
    if (pill) {
      const w = ui.nodeEls.get(after.id)?.offsetWidth || 296;
      pill.style.left = `${after.x + w + 72}px`;
      pill.style.top = `${after.y + PORT_Y - 19}px`;
    }
  }
  if (temp) {
    layer.append(svg('path', { class: 'temp', d: curve(temp.a, temp.b) }));
  }
}

// Drag from an output port: drop on a node to connect, on empty space to pick.
function startConnect(n, port, event) {
  event.stopPropagation();
  event.preventDefault();
  if (running) return;
  port.classList.add('armed');
  const a = outPoint(n);
  let target = null;
  const move = e => {
    const b = toWorld(e.clientX, e.clientY);
    renderEdges({ a, b });
    const hit = document.elementFromPoint(e.clientX, e.clientY)?.closest?.('.cv-node');
    const id = hit?.dataset.id;
    const candidate = id && id !== n.id ? nodeById(id) : null;
    if (target) ui.nodeEls.get(target.id)?.classList.remove('drop-ok');
    target = candidate && canFollow(n, candidate.type, candidate) ? candidate : null;
    if (target) ui.nodeEls.get(target.id)?.classList.add('drop-ok');
  };
  const up = e => {
    removeEventListener('pointermove', move);
    removeEventListener('pointerup', up);
    port.classList.remove('armed');
    if (target) {
      ui.nodeEls.get(target.id)?.classList.remove('drop-ok');
      connect(n.id, target.id);
      return;
    }
    renderEdges();
    const over = document.elementFromPoint(e.clientX, e.clientY);
    if (over?.closest?.('.cv-node')) return toast('That step cannot follow this one.', true);
    const p = toWorld(e.clientX, e.clientY);
    if (Math.hypot(p.x - a.x, p.y - a.y) < 12) return;
    pendingLine = { a, b: p };
    renderEdges(pendingLine);
    openNextMenu(n, e.clientX, e.clientY, p);
  };
  addEventListener('pointermove', move);
  addEventListener('pointerup', up);
}
function connect(from, to) {
  const a = nodeById(from);
  flow.edges = flow.edges.filter(e => !(e.from === from && e.to === to) && (multiOut(a) || e.from !== from) && e.to !== to);
  flow.edges.push({ from, to });
  persist();
  renderAll();
}

let menu = null;
let pendingLine = null; // the dragged line, kept while its menu is open
function closeMenu() {
  menu?.remove();
  menu = null;
  if (pendingLine) {
    pendingLine = null;
    renderEdges();
  }
}
function openNextMenu(prev, cx, cy, p) {
  // Close an older menu but keep the line being dropped.
  const keep = pendingLine;
  pendingLine = null;
  closeMenu();
  pendingLine = keep;
  if (keep) renderEdges(keep);
  const valid = Object.keys(TYPES).filter(type => canFollow(prev, type));
  if (!valid.length) return toast('Nothing can follow this step.', true);
  const r = ui.stage.getBoundingClientRect();
  menu = el('div', 'cv-menu');
  menu.append(el('h5', '', 'Add the next step'));
  for (const type of valid) {
    menu.append(paletteItem(type, () => {
      closeMenu();
      const n = addNode(type, p.x + 8, p.y - PORT_Y, true);
      connect(prev.id, n.id);
    }));
  }
  menu.style.left = `${Math.min(cx - r.left, r.width - 220)}px`;
  menu.style.top = `${Math.min(cy - r.top, r.height - 40 * valid.length - 40)}px`;
  ui.stage.append(menu);
}

function addNode(type, x, y, quiet) {
  const n = { id: uid(), type, x: Math.round(x), y: Math.round(y), data: defaults(type) };
  flow.nodes.push(n);
  renderAll();
  settle(n);
  persist();
  if (!quiet) toast(`${TYPES[type].title} added. Connect it from the dot of the step before.`);
  return n;
}
// A card's box in world units (its rendered size, or a usual card size).
function boxOf(n) {
  const node = ui.nodeEls.get(n.id);
  return { x: n.x, y: n.y, w: node?.offsetWidth || 296, h: node?.offsetHeight || 160 };
}
// Move a card to the nearest spot where it does not cover another card.
function settle(n) {
  const spot = freeSpot(boxOf(n), flow.nodes.filter(m => m !== n).map(boxOf));
  if (!spot || (spot.x === n.x && spot.y === n.y)) return;
  n.x = Math.round(spot.x);
  n.y = Math.round(spot.y);
  const node = ui.nodeEls.get(n.id);
  if (node) {
    node.style.left = `${n.x}px`;
    node.style.top = `${n.y}px`;
  }
  requestAnimationFrame(() => renderEdges());
}
// Click on a step in the list: add it after the step it most likely follows.
function appendToChain(type) {
  const plan = planOf();
  const width = n => ui.nodeEls.get(n.id)?.offsetWidth || 296;
  const height = n => ui.nodeEls.get(n.id)?.offsetHeight || 160;
  // The newest step in the tree this one can follow, with room for it.
  const inTree = [plan.start, ...plan.servers, ...plan.lines.flatMap(l => [l.source, ...l.settings])];
  const parent = inTree.reverse().find(n => canFollow(n, type) && (multiOut(n) || !nextOf(n.id)));
  if (!parent) return addNode(type, ...centre());
  const siblings = multiOut(parent) ? outsOf(parent.id).map(id => nodeById(id)).filter(Boolean) : [];
  const y = siblings.length ? Math.max(...siblings.map(n => n.y + height(n))) + 40 : parent.y;
  const n = addNode(type, parent.x + width(parent) + 80, y, true);
  connect(parent.id, n.id);
}
function centre() {
  const r = ui.viewport.getBoundingClientRect();
  const p = toWorld(r.left + r.width / 2, r.top + r.height / 2);
  return [p.x - 140, p.y - 60];
}

// ── Nodes ──────────────────────────────────────────────────────────────
function renderAll() {
  for (const node of ui.nodeEls.values()) node.remove();
  ui.nodeEls.clear();
  for (const n of flow.nodes) ui.world.append(renderNode(n));
  renderResult();
  applyView();
  requestAnimationFrame(() => renderEdges());
}
function renderNode(n) {
  const t = TYPES[n.type];
  const node = el('div', `cv-node${n.type === 'start' ? ' start' : ''}`);
  node.dataset.id = n.id;
  node.style.left = `${n.x}px`;
  node.style.top = `${n.y}px`;
  const st = runState[n.id];
  if (st) node.classList.add(st.state);
  const handle = el('div', 'cv-node-head');
  const badge = el('i');
  badge.append(icon(t.icon));
  const titles = el('div');
  titles.append(el('b', '', t.title), el('small', '', t.sub));
  handle.append(badge, titles);
  if (n.type !== 'start' && n.type !== 'deploy' && n.id !== 'server') {
    const x = el('button', 'cv-x');
    x.title = 'Remove step';
    x.append(icon(ICONS.x));
    x.addEventListener('pointerdown', e => e.stopPropagation());
    x.addEventListener('click', () => removeNode(n.id));
    handle.append(x);
  }
  const body = el('div', 'cv-node-body');
  fillBody(body, n);
  node.append(handle, body);
  if (n.type !== 'start') node.append(el('span', 'cv-port in'));
  if (nextOf(n.id) || planOf().lines.some(l => l.end.id === n.id)) node.classList.add('has-out');
  if (prevOf(n.id) || (n.type === 'deploy' && planOf().lines.length)) node.classList.add('has-in');
  if (st?.message) node.append(el('div', `cv-status ${st.state}`, st.message));
  if (n.type !== 'deploy') {
    const pout = el('span', 'cv-port out');
    pout.title = 'Drag to connect the next step';
    pout.addEventListener('pointerdown', e => startConnect(n, pout, e));
    node.append(pout);
  }
  if (n.id === 'server' && flow.nodes.every(x => ['start', 'server', 'deploy'].includes(x.type))) {
    node.classList.add('blank');
    const hint = el('div', 'cv-hint');
    hint.append(el('span', '', 'Drag and drop to add a step'));
    node.append(hint);
  }
  // Drag the node by its handle.
  // Pointer capture keeps the drag even when the pointer outruns the card;
  // the card is looked up each move (a redraw may have replaced it).
  handle.addEventListener('pointerdown', event => {
    if (event.button !== 0 || event.target.closest('button, input, select, textarea')) return;
    event.stopPropagation();
    event.preventDefault();
    closeMenu();
    try { handle.setPointerCapture(event.pointerId); } catch { /* not capturable */ }
    const ox = event.clientX / view.z - n.x;
    const oy = event.clientY / view.z - n.y;
    dragging = true;
    // The other cards stay put during the drag, so measure them once.
    const others = flow.nodes.filter(m => m !== n).map(boxOf);
    const move = e => {
      if (e.pointerId !== event.pointerId) return;
      const current = ui.nodeEls.get(n.id) || node;
      const wanted = { x: e.clientX / view.z - ox, y: e.clientY / view.z - oy, w: current.offsetWidth, h: current.offsetHeight };
      // Cards cannot cover each other: stop at the edge of a card in the way.
      const spot = freeSpot(wanted, others);
      if (!spot) return;
      n.x = Math.round(spot.x);
      n.y = Math.round(spot.y);
      current.style.left = `${n.x}px`;
      current.style.top = `${n.y}px`;
      renderEdges();
    };
    const up = e => {
      if (e.pointerId !== event.pointerId) return;
      dragging = false;
      removeEventListener('pointermove', move);
      removeEventListener('pointerup', up);
      removeEventListener('pointercancel', up);
      persist();
    };
    addEventListener('pointermove', move);
    addEventListener('pointerup', up);
    addEventListener('pointercancel', up);
  });
  ui.nodeEls.set(n.id, node);
  return node;
}
// A pill joined to the Deploy card: each address opens in a new tab.
function renderResult() {
  ui.world.querySelector('.cv-result')?.remove();
  const after = result && nodeById(result.after);
  if (!after) return;
  const pill = el('div', 'cv-result');
  const w = ui.nodeEls.get(after.id)?.offsetWidth || 296;
  pill.style.left = `${after.x + w + 72}px`;
  pill.style.top = `${after.y + PORT_Y - 19}px`;
  pill.append(el('span', 'cv-port in'));
  const dot = el('i');
  pill.append(dot);
  const links = result.urls.length ? result.urls : [];
  if (links.length) {
    for (const url of links.slice(0, 3)) {
      const a = el('a', '', url.replace(/^https?:\/\//, ''));
      a.href = url;
      a.target = '_blank';
      a.rel = 'noopener noreferrer';
      a.title = `Open ${url}`;
      a.append(icon(ICONS.out));
      pill.append(a);
    }
  } else {
    const a = el('a', '', 'Open in Coolify');
    a.href = result.href;
    a.target = '_blank';
    pill.append(a);
  }
  const x = el('button', 'cv-x');
  x.title = 'Hide';
  x.append(icon(ICONS.x));
  x.addEventListener('click', () => {
    result = null;
    save('result', null);
    renderResult();
    renderEdges();
  });
  pill.append(x);
  pill.addEventListener('pointerdown', e => e.stopPropagation());
  ui.world.append(pill);
}

function removeNode(id) {
  if (running) return;
  flow.nodes = flow.nodes.filter(n => n.id !== id);
  flow.edges = flow.edges.filter(e => e.from !== id && e.to !== id);
  delete runState[id];
  persist();
  renderAll();
}

function fillBody(body, n) {
  const t = TYPES[n.type];
  body.replaceChildren();
  for (const f of t.fields) {
    if (f.show && !f.show(n.data)) continue;
    if (f.kind === 'note') {
      body.append(el('p', 'cv-note', f.text));
      continue;
    }
    const wrap = el('div', 'cv-field');
    const label = el('label', '', f.label);
    if (f.required) label.append(el('em', '', ' *'));
    wrap.append(label);
    const set = value => {
      n.data[f.key] = value;
      f.after?.(n.data);
      if (f.rerender || f.kind === 'select') {
        // Reset fields that depend on this one.
        if (f.rerender) for (const other of t.fields) if (other.load && other !== f && other.key !== f.key && t.fields.indexOf(other) > t.fields.indexOf(f)) delete n.data[other.key];
        fillBody(body, n);
        requestAnimationFrame(() => renderEdges());
      }
      ui.nodeEls.get(n.id)?.classList.remove('invalid');
      if (t.stage === 'source') refreshDeployBox();
      persist();
    };
    if (f.kind === 'segmented') {
      const seg = el('div', 'cv-seg');
      for (const [value, text] of f.options) {
        const b = el('button', (n.data[f.key] ?? f.value) === value ? 'on' : '', text);
        b.type = 'button';
        b.addEventListener('click', () => {
          for (const other of seg.children) other.classList.toggle('on', other === b);
          set(value);
        });
        b.addEventListener('pointerdown', e => e.stopPropagation());
        seg.append(b);
      }
      wrap.append(seg);
    } else if (f.kind === 'select') {
      // Our dropdown: a field-like trigger, a list in a floating panel.
      const list = f.load ? f.load(n.data) : f.options;
      const only = f.only && onlyOption(list, n.data[f.key]);
      if (only && only !== n.data[f.key]) {
        n.data[f.key] = only;
        persist();
      }
      // null = loading; undefined / '' = it needs the field above first.
      const waiting = list === null ? 'Loading…' : 'Pick the field above first';
      const value = n.data[f.key] ?? '';
      // An optional field's default ("Default") is a real choice, not a placeholder.
      const chosen = (list || []).find(([v]) => v === value) || (list && f.optional && !value ? ['', f.optional] : null);
      const trigger = el('button', `cv-input cv-select${chosen ? '' : ' empty'}`);
      trigger.type = 'button';
      trigger.disabled = !list;
      trigger.setAttribute('aria-haspopup', 'listbox');
      trigger.append(el('span', '', chosen ? chosen[1] : list ? (f.optional || `Choose ${f.label.toLowerCase()}`) : waiting));
      if (chosen?.[2]) trigger.append(el('em', 'cv-badge', chosen[2]));
      trigger.append(icon(ICONS.chevron));
      trigger.addEventListener('pointerdown', e => e.stopPropagation());
      const add = f.add && canAdd ? {
        ...f.add,
        create: name => f.add.create(name, n.data),
        // Load the list again (it has the new one), then select it.
        done: picked => {
          delete cache[f.add.list(n.data)];
          set(picked);
        },
      } : null;
      trigger.addEventListener('click', () => openDropdown(trigger, [...(f.optional ? [['', f.optional]] : []), ...(list || [])], value, picked => set(picked), add));
      trigger.addEventListener('keydown', e => {
        if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          trigger.click();
        }
      });
      wrap.append(trigger);
      const err = f.load && Object.entries(errorsOf).find(([k]) => k === loaderKey(f, n.data))?.[1];
      if (err) wrap.append(el('span', 'cv-note bad', err));
    } else {
      const input = el(f.kind === 'textarea' ? 'textarea' : 'input', `cv-input${f.mono ? ' mono' : ''}`);
      if (f.kind === 'textarea') input.rows = f.rows || 3;
      else input.type = 'text';
      input.placeholder = f.placeholder || '';
      input.value = n.data[f.key] ?? '';
      input.spellcheck = false;
      input.addEventListener('input', () => set(input.value));
      input.addEventListener('pointerdown', e => e.stopPropagation());
      input.addEventListener('keydown', e => e.stopPropagation());
      wrap.append(input);
    }
    body.append(wrap);
  }
  if (n.type === 'deploy') {
    const lines = planOf().lines;
    if (!lines.length) body.append(el('p', 'cv-note', 'Every resource you connect to a Server shows here.'));
    for (const { source } of lines) {
      const wrap = el('div', 'cv-field');
      wrap.append(el('label', '', resourceLabel(source)));
      body.append(wrap);
    }
    // Save only creates the resources in Coolify; Deploy also starts them.
    const actions = el('div', 'cv-actions');
    for (const [action, text, cls] of [['save', 'Save only', 'button'], ['deploy', 'Deploy', 'button button-highlighted']]) {
      const b = el('button', `${cls} cv-deploy-now`);
      b.type = 'button';
      if (action === 'deploy') b.append(icon(ICONS.deploy));
      b.append(el('span', '', running === action ? 'Working…' : text));
      b.disabled = !!running;
      b.title = action === 'save' ? 'Create the resources in Coolify without starting them' : 'Create the resources and deploy them';
      b.addEventListener('pointerdown', e => e.stopPropagation());
      b.addEventListener('click', () => runFlow(action));
      actions.append(b);
    }
    body.append(actions);
  }
  if (TYPES[n.type].stage === 'config') {
    if (!sourceAbove(n.id)) body.append(el('p', 'cv-note', 'Connect it after a source step.'));
  }
}
function refreshDeployBox() {
  const deploy = planOf().deploy;
  const body = deploy && ui.nodeEls.get(deploy.id)?.querySelector('.cv-node-body');
  if (!body) return;
  fillBody(body, deploy);
  requestAnimationFrame(() => renderEdges());
}
// The cache key a select loads from (to show its error).
function loaderKey(f, d) {
  const map = { project: 'projects', environment: `envs:${d.project}`, server: 'servers', destination: `dest:${d.server}`, ghApp: 'gh', ghRepo: `repos:${d.ghApp}`, ghBranch: `br:${d.ghApp}:${d.ghRepo}`, key: 'keys' };
  return map[f.key];
}
// Re-fill node bodies after lists load (not under a field being typed in).
let refreshTimer = 0;
let dragging = false;
function refreshBodies() {
  if (!ui) return;
  if (dragging) {
    setTimeout(refreshBodies, 200);
    return;
  }
  cancelAnimationFrame(refreshTimer);
  refreshTimer = requestAnimationFrame(() => {
    for (const n of flow.nodes) {
      const node = ui.nodeEls.get(n.id);
      if (!node || node.contains(document.activeElement)) continue;
      const body = node.querySelector('.cv-node-body');
      if (body) fillBody(body, n);
    }
    renderEdges();
  });
}

// ── Run ────────────────────────────────────────────────────────────────
function buildRunPanel() {
  const root = el('aside', 'cv-run');
  root.hidden = true;
  const header = el('header');
  const text = el('div');
  const title = el('b', '', 'Deploy');
  const sub = el('span', '', '');
  text.append(title, sub);
  const closeBtn = el('button', 'cv-x');
  closeBtn.title = 'Close';
  closeBtn.append(icon(ICONS.x));
  closeBtn.addEventListener('click', () => { if (!running) root.hidden = true; });
  header.append(text, closeBtn);
  const steps = el('div', 'cv-steps');
  const tabs = el('div', 'cv-seg cv-tabs');
  tabs.setAttribute('role', 'tablist');
  const footer = el('footer');
  const openLink = el('a', 'button', 'Open');
  openLink.target = '_blank';
  openLink.hidden = true;
  footer.append(openLink);
  const logsWrap = el('div', 'cv-logs-wrap');
  root.append(header, steps, tabs, logsWrap, footer);
  return { root, title, sub, steps, tabs, logsWrap, openLink, panes: new Map(), active: null, defaultHref: '' };
}
// Log panes: "all" holds the steps; each deployment gets its own tab and pane.
function resetPanes() {
  const r = ui.run;
  r.tabs.replaceChildren();
  r.logsWrap.replaceChildren();
  r.panes.clear();
  r.active = null;
  r.defaultHref = '';
  openPane('all', 'Steps');
  showPane('all');
}
function openPane(key, label) {
  const r = ui.run;
  if (r.panes.has(key)) return r.panes.get(key);
  const tab = el('button');
  tab.type = 'button';
  tab.setAttribute('role', 'tab');
  tab.title = label;
  const dot = el('i');
  // "Docker image · nginx #2" → "nginx #2"; the full name is the tooltip.
  tab.append(dot, el('span', '', label.includes(' · ') ? label.slice(label.indexOf(' · ') + 3) : label));
  tab.addEventListener('click', () => showPane(key));
  const pre = el('pre', 'cv-logs');
  pre.hidden = true;
  const pane = { tab, dot, pre, label, href: '' };
  r.panes.set(key, pane);
  r.tabs.append(tab);
  r.logsWrap.append(pre);
  r.tabs.hidden = r.panes.size < 2;
  return pane;
}
function showPane(key) {
  const r = ui.run;
  r.active = key;
  for (const [k, pane] of r.panes) {
    pane.tab.classList.toggle('on', k === key);
    pane.tab.setAttribute('aria-selected', String(k === key));
    pane.pre.hidden = k !== key;
  }
  const href = r.panes.get(key)?.href || r.defaultHref;
  if (href) r.openLink.href = href;
}
function setPaneState(key, state, status) {
  const pane = ui.run.panes.get(key);
  if (!pane) return;
  pane.dot.className = state;
  pane.tab.title = `${pane.label}${status ? `: ${status}` : ''}`;
}
function logLine(text, cls, key = 'all') {
  const line = el('span', cls || '', `${text}\n`);
  const box = (ui.run.panes.get(key) || ui.run.panes.get('all')).pre;
  const atEnd = box.scrollTop + box.clientHeight >= box.scrollHeight - 30;
  box.append(line);
  if (atEnd) box.scrollTop = box.scrollHeight;
}
function setStep(n, state, message) {
  runState[n.id] = { state, message };
  const node = ui.nodeEls.get(n.id);
  if (node) {
    node.classList.remove('running', 'done', 'failed', 'invalid');
    node.classList.add(state);
    node.querySelector(':scope > .cv-status')?.remove();
    if (message) node.append(el('div', `cv-status ${state}`, message));
  }
  const row = ui.run.steps.querySelector(`[data-id="${n.id}"]`);
  if (row) {
    row.className = `cv-step ${state}`;
    row.querySelector('small').textContent = message || '';
  }
  renderEdges();
}

function validate(plan) {
  const problems = [];
  const mark = n => ui.nodeEls.get(n.id)?.classList.add('invalid');
  if (!plan.servers.length) problems.push('Connect a Server right after Start.');
  else if (!plan.lines.length) problems.push('Connect a source step (Git repository, Docker image, Dockerfile, Service or Database) to a Server.');
  for (const n of [plan.start, ...new Set(plan.lines.map(l => l.server)), ...plan.lines.flatMap(l => [l.source, ...l.settings])]) {
    const missing = TYPES[n.type].fields.filter(f => f.required && (!f.show || f.show(n.data)) && !String(n.data[f.key] ?? '').trim()).map(f => f.label);
    if (missing.length) {
      problems.push(`${TYPES[n.type].title}: fill in ${missing.join(', ')}.`);
      mark(n);
    }
  }
  return problems;
}

async function runFlow(action = 'deploy') {
  if (running) return;
  closeMenu();
  const plan = planOf();
  const problems = validate(plan);
  if (problems.length) {
    toast(problems[0], true);
    return;
  }
  running = action;
  runState = {};
  result = null;
  save('result', null);
  save('deployed', false);
  const servers = [...new Set(plan.lines.map(l => l.server))];
  const list = [plan.start, ...servers, ...plan.lines.flatMap(l => [l.source, ...l.settings]), plan.deploy];
  const names = uniqueLabels(plan.lines.map(l => l.source));
  const labelOf = n => names.get(n.id) || TYPES[n.type].title;
  const r = ui.run;
  r.root.hidden = false;
  r.title.textContent = action === 'save' ? 'Saving' : 'Deploying';
  r.sub.textContent = `${plan.lines.length} resource${plan.lines.length === 1 ? '' : 's'}`;
  resetPanes();
  r.openLink.hidden = true;
  r.steps.replaceChildren(...list.map(n => {
    const row = el('div', 'cv-step');
    row.dataset.id = n.id;
    row.append(el('i'));
    const text = el('div');
    text.append(el('span', '', labelOf(n)), el('small', '', 'Waiting'));
    row.append(text);
    return row;
  }));
  renderAll();
  // The page can be left (and the canvas mounted again) during a run.
  const owner = ui;
  // Run one step; false when it failed.
  const step = async (n, work) => {
    setStep(n, 'running', 'Running…');
    try {
      const message = await work();
      if (ui !== owner) return false;
      setStep(n, 'done', message);
      logLine(`✓ ${labelOf(n)}: ${message}`, 'dim');
      return true;
    } catch (error) {
      if (ui !== owner) return false;
      setStep(n, 'failed', error.message);
      logLine(`✗ ${labelOf(n)}: ${error.message}`, 'err');
      return false;
    }
  };
  const base = {};
  let ok = await step(plan.start, () => TYPES.start.run(base, plan.start.data));
  for (const server of servers) {
    if (!ok) break;
    ok = await step(server, async () => 'Server picked.');
  }
  const shipped = []; // { line, ctx } per created resource
  for (const line of plan.lines) {
    if (!ok) break;
    const ctx = { ...base };
    await TYPES.server.run(ctx, line.server.data);
    const domainNode = line.settings.find(n => n.type === 'domain');
    if (domainNode) ctx.domains = domainList(domainNode.data.domains);
    for (const n of [line.source, ...line.settings]) {
      ok = await step(n, () => TYPES[n.type].run(ctx, n.data));
      if (!ok) break;
    }
    if (ctx.resource) shipped.push({ line, ctx, label: names.get(line.source.id) });
  }
  if (ui !== owner) return;
  const href = () => shipped.length === 1 ? resourceHref(shipped[0].ctx) : `/project/${base.project}/environment/${base.environment}`;
  if (shipped.length) {
    r.openLink.hidden = false;
    r.openLink.href = r.defaultHref = href();
  }
  if (ok) {
    ok = await step(plan.deploy, async () => action === 'save'
      ? `Saved ${shipped.length} resource${shipped.length === 1 ? '' : 's'}. Not deployed.`
      : deployAll(shipped));
  }
  if (ui !== owner) return;
  if (shipped.length) r.defaultHref = href();
  showPane(r.active);
  if (ok) {
    const urls = [];
    for (const { ctx } of action === 'save' ? [] : shipped) {
      let found = String(ctx.domains || ctx.createdDomains || '').split(',').map(v => v.trim()).filter(Boolean);
      if (!found.length && ctx.resource.kind === 'application') {
        try {
          const app = await api('GET', `/applications/${ctx.resource.uuid}`);
          found = String(app?.fqdn || '').split(',').map(v => v.trim()).filter(Boolean);
        } catch { /* keep none */ }
      }
      urls.push(...found);
    }
    result = { after: plan.deploy.id, urls, href: href() };
    save('result', result);
    // The next visit starts with an empty canvas.
    if (action === 'deploy') save('deployed', true);
    renderResult();
    renderEdges();
  }
  r.title.textContent = ok ? 'Done' : 'Stopped';
  r.sub.textContent = ok ? (action === 'save' ? 'Saved in Coolify. Deploy them from there or from here.' : 'Every step finished.') : 'A step failed. Fix it and try again.';
  running = false;
  refreshDeployBox();
}
// Deploy every resource at once and follow them.
async function deployAll(shipped) {
  const many = shipped.length > 1;
  // A tab per resource, in order; the first one opens.
  for (const { line, ctx, label } of shipped) openPane(line.source.id, label).href = resourceHref(ctx);
  if (shipped.length) showPane(shipped[0].line.source.id);
  const results = await Promise.allSettled(shipped.map(async ({ line, ctx, label }) => {
    const key = line.source.id;
    setPaneState(key, 'running', 'Starting');
    try {
      const res = await api('POST', '/deploy', { uuid: ctx.resource.uuid });
      const item = res?.deployments?.[0];
      let message = item?.message || 'Started.';
      if (item?.deployment_uuid) {
        ctx.deployment = item.deployment_uuid;
        ui.run.panes.get(key).href = resourceHref(ctx);
        if (ui.run.active === key) showPane(key);
        message = await followDeployment(label, item.deployment_uuid, key);
      } else {
        logLine(message, '', key);
      }
      setPaneState(key, 'done', message);
      logLine(`✓ ${label}: ${message}`, 'dim');
      return `${label}: ${message}`;
    } catch (error) {
      setPaneState(key, 'failed', error.message.replace(`${label}: `, ''));
      logLine(error.message, 'err', key);
      throw error;
    }
  }));
  const failed = results.filter(res => res.status === 'rejected').map(res => res.reason.message);
  if (failed.length) throw new Error(failed.join(' · '));
  return many ? `${results.length} resources deployed.` : results[0].value;
}
function resourceHref(ctx) {
  const base = `/project/${ctx.project}/environment/${ctx.environment}`;
  const kind = { application: 'application', service: 'service', database: 'database' }[ctx.resource.kind];
  return ctx.deployment && kind === 'application' ? `${base}/application/${ctx.resource.uuid}/deployment/${ctx.deployment}` : `${base}/${kind}/${ctx.resource.uuid}`;
}

// Poll the deployment (Coolify's own log view also polls every 2 s).
// Logs go to the resource's own pane (key).
async function followDeployment(label, deploymentUuid, key) {
  logLine(`Deployment ${deploymentUuid}`, 'dim', key);
  const owner = ui;
  let shown = 0;
  let warned = false;
  for (;;) {
    if (ui !== owner) return 'Left the canvas.';
    let dep;
    try {
      dep = await api('GET', `/deployments/${deploymentUuid}`);
    } catch (error) {
      logLine(`(could not read the deployment: ${error.message})`, 'err', key);
      await sleep(LOG_POLL_MS * 2);
      continue;
    }
    let entries = [];
    if (typeof dep.logs === 'string') {
      try { entries = JSON.parse(dep.logs) || []; } catch { entries = []; }
    } else if (dep.logs === undefined && !warned) {
      warned = true;
      logLine('Logs are hidden: only team admins can see deployment logs.', 'err', key);
    }
    const visible = entries.filter(entry => !entry.hidden);
    for (const entry of visible.slice(shown)) {
      for (const line of String(entry.output ?? '').split('\n')) if (line.trim()) logLine(line, entry.type === 'stderr' ? 'err' : '', key);
    }
    shown = visible.length;
    setPaneState(key, 'running', String(dep.status || '').replace(/_/g, ' '));
    if (dep.status === 'finished') return 'Deployed.';
    if (dep.status === 'failed') throw new Error(`${label}: deployment failed. See the logs.`);
    if (dep.status === 'cancelled-by-user') throw new Error(`${label}: deployment cancelled.`);
    await sleep(LOG_POLL_MS);
  }
}

// The "New …" row of a dropdown: a click shows a name field; Enter creates it.
function addRow(add, typed) {
  const foot = el('div', 'cv-dd-add');
  const open = el('button', 'cv-dd-opt cv-dd-new');
  open.type = 'button';
  open.append(icon(ICONS.plus), el('span', '', add.label));
  open.addEventListener('click', () => {
    const form = el('form', 'cv-dd-form');
    const input = el('input', 'cv-dd-search');
    input.placeholder = add.placeholder;
    input.value = typed();
    input.spellcheck = false;
    // Enter submits the form, not the list's "pick the active option".
    input.addEventListener('keydown', e => { if (e.key !== 'Escape') e.stopPropagation(); });
    const go = el('button', 'button button-highlighted', 'Add');
    go.type = 'submit';
    const problem = el('p', 'cv-note bad');
    form.addEventListener('submit', async e => {
      e.preventDefault();
      const name = input.value.trim();
      if (!name || go.disabled) return;
      go.disabled = true;
      try {
        const created = await add.create(name);
        closeDropdown();
        add.done(created);
        toast(`"${name}" added.`);
      } catch (error) {
        problem.textContent = error.message;
        go.disabled = false;
        input.focus();
      }
    });
    form.append(input, go);
    foot.replaceChildren(form, problem);
    input.focus();
  });
  foot.append(open);
  return foot;
}

// ── Dropdown panel (fixed in the canvas root, so zoom never shrinks it) ──
let dropdown = null;
function closeDropdown() {
  if (!dropdown) return;
  dropdown.panel.remove();
  dropdown.trigger.classList.remove('open');
  removeEventListener('pointerdown', dropdown.outside, true);
  dropdown = null;
}
function openDropdown(trigger, items, current, onPick, add = null) {
  if (dropdown?.trigger === trigger) return closeDropdown();
  closeDropdown();
  const panel = el('div', 'cv-dd');
  panel.setAttribute('role', 'listbox');
  let search = null;
  if (items.length > 8) {
    search = el('input', 'cv-dd-search');
    search.placeholder = 'Search';
    search.spellcheck = false;
    panel.append(search);
  }
  const listBox = el('div', 'cv-dd-list');
  panel.append(listBox);
  let active = Math.max(0, items.findIndex(([v]) => v === current));
  let shown = items;
  const draw = () => {
    const q = search?.value.trim().toLowerCase() || '';
    shown = items.filter(([, text]) => !q || String(text).toLowerCase().includes(q));
    active = Math.min(active, Math.max(0, shown.length - 1));
    listBox.replaceChildren(...(shown.length ? shown.map(([v, text, badge], i) => {
      const opt = el('button', `cv-dd-opt${v === current ? ' on' : ''}${i === active ? ' active' : ''}`);
      opt.type = 'button';
      opt.setAttribute('role', 'option');
      opt.append(el('span', '', text));
      if (badge) opt.append(el('em', 'cv-badge', badge));
      opt.append(icon(ICONS.check));
      opt.addEventListener('pointerenter', () => {
        active = i;
        for (const o of listBox.children) o.classList.toggle('active', o === opt);
      });
      opt.addEventListener('click', () => {
        closeDropdown();
        onPick(v);
      });
      return opt;
    }) : [el('p', 'cv-dd-none', q ? 'No match' : 'None yet')]));
    listBox.children[active]?.scrollIntoView({ block: 'nearest' });
  };
  const keys = e => {
    if (e.key === 'Escape') {
      e.preventDefault();
      closeDropdown();
      trigger.focus();
    } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      active = (active + (e.key === 'ArrowDown' ? 1 : -1) + shown.length) % Math.max(1, shown.length);
      draw();
    } else if (e.key === 'Enter' && shown[active]) {
      e.preventDefault();
      closeDropdown();
      onPick(shown[active][0]);
    }
  };
  panel.addEventListener('keydown', keys);
  if (add) panel.append(addRow(add, () => search?.value.trim() || ''));
  search?.addEventListener('input', () => {
    active = 0;
    draw();
  });
  draw();
  ui.root.append(panel);
  // Under the trigger (or above it when there is no room), as wide as it.
  const r = trigger.getBoundingClientRect();
  const width = Math.max(r.width, 200);
  const below = innerHeight - r.bottom - 12;
  panel.style.width = `${width}px`;
  panel.style.left = `${Math.min(r.left, innerWidth - width - 12)}px`;
  if (below < Math.min(panel.offsetHeight, 260) && r.top > below) panel.style.bottom = `${innerHeight - r.top + 6}px`;
  else panel.style.top = `${r.bottom + 6}px`;
  trigger.classList.add('open');
  const outside = e => {
    if (!panel.contains(e.target) && e.target !== trigger && !trigger.contains(e.target)) closeDropdown();
  };
  addEventListener('pointerdown', outside, true);
  dropdown = { panel, trigger, outside };
  (search || listBox.children[active] || panel).focus?.();
}

let toastTimer = 0;
function toast(text, bad) {
  ui.stage.querySelector('.cv-toast')?.remove();
  const t = el('div', `cv-toast${bad ? ' bad' : ''}`, text);
  ui.stage.append(t);
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => t.remove(), bad ? 4200 : 2600);
}

// Palette: full or icons only ('coolify-canvas:collapsed').
function applyCollapsed() {
  if (!ui) return;
  const folded = load('collapsed', false);
  ui.root.classList.toggle('cv-folded', folded);
  const fold = ui.root.querySelector('.cv-fold');
  fold.title = folded ? 'Show the step names' : 'Collapse the steps';
  fold.setAttribute('aria-label', fold.title);
  fold.setAttribute('aria-expanded', String(!folded));
}

// Theme: same as Coolify (html.dark).
function applyTheme() {
  if (!ui) return;
  ui.root.classList.toggle('cv-dark', document.documentElement.classList.contains('dark'));
}

// Small screens: a notice that the canvas is not optimized for mobile. CSS
// shows it below 768px; closed once, it stays closed ('coolify-canvas:mobile-notice-dismissed').
function buildNotice() {
  if (load('mobile-notice-dismissed', false)) return null;
  const notice = el('div', 'cv-island cv-notice');
  notice.setAttribute('role', 'note');
  const text = el('p', '', 'The canvas is not optimized for mobile yet. It works best on a larger screen.');
  if (target) {
    const link = el('a', '', 'New resource page');
    link.href = `/project/${target.project}/environment/${target.environment}/new`;
    text.append(' On a phone, use the ', link, '.');
  }
  const close = el('button', 'cv-x');
  close.type = 'button';
  close.title = 'Close';
  close.setAttribute('aria-label', 'Close this notice');
  close.append(icon(ICONS.x));
  close.addEventListener('click', () => {
    save('mobile-notice-dismissed', true);
    notice.remove();
  });
  notice.append(icon(ICONS.info), text, close);
  return notice;
}

// ── Mount ──────────────────────────────────────────────────────────────
export function mountDeployCanvas(container) {
  canAdd = container.dataset.canAdd === '1';
  if (load('deployed', false)) {
    save('flow', null);
    save('result', null);
    save('deployed', false);
  }
  target = targetFrom(location.search);
  flow = withTarget(withDeploy(load('flow', null) || fresh()), target);
  save('flow', withoutSecrets(flow)); // drops secrets that older versions stored
  view = load('view', { x: 40, y: 40, z: 1 });
  result = load('result', null);
  runState = {};
  running = false;
  menu = null;
  pendingLine = null;
  dropdown = null;
  build(container);
  applyTheme();
  applyCollapsed();
  renderAll();
  // Small screens: the default view is made for desktop, so the first visit fits the steps instead.
  if (!load('view', null) && !matchMedia('(min-width: 768px)').matches) requestAnimationFrame(fit);
}
function resetCanvas() {
  if (running) return;
  flow = withTarget(fresh(), target);
  runState = {};
  result = null;
  save('result', null);
  save('deployed', false);
  persist();
  renderAll();
}
export function initializeDeployCanvas() {
  new MutationObserver(() => applyTheme()).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
  addEventListener('keydown', event => {
    if (ui?.root.isConnected && event.key === 'Escape') closeMenu();
  });
  const replaceTop = () => {
    if (ui?.root.isConnected) placeTop();
  };
  matchMedia(DESKTOP).addEventListener('change', replaceTop);
  addEventListener('resize', replaceTop);
  new MutationObserver(replaceTop).observe(document.documentElement, { attributes: true, attributeFilter: ['data-nav-style'] });
  addEventListener('deploy-canvas-reset', () => {
    if (ui?.root.isConnected) resetCanvas();
  });
  mountOnPage('[data-deploy-canvas]', '.cv-root', mountDeployCanvas);
}
