# Decision 0025: Deploy each pull request as a preview workload

**Status: Accepted**

**Date: 2026-11-15**

## Context

Decision 0011 says: "Each pull request becomes a separate workload with a
generated domain. Coolify removes it when the pull request closes." It leaves
previews of applications with volumes open. This is roadmap item L5.

### Previews in v4

- `ApplicationPreview` is a child record of an application, with
  `pull_request_id`, `pull_request_html_url`, `fqdn`, `git_type`, and
  `pull_request_issue_comment_id`. The deployment job runs the parent
  application with `pull_request_id` set. Each code path checks
  `pull_request_id !== 0` (more than 30 places in `ApplicationDeploymentJob`).
- Settings of the application: `is_preview_deployments_enabled` (previews on),
  `is_pr_deployments_public_enabled` (previews of forks on), and
  `use_production_values_in_previews` (default on).
- The domain is `preview_url_template` (default `{{pr_id}}.{{domain}}`) on the
  first domain of the application. `{{random}}` is also available.
- Environment variables with `is_preview = true` are the preview values. A new
  production variable gets an automatic preview copy. A preview gets the
  production values only when "Use production values in previews" is on and
  previews of forks are off.
- Triggers:
  - GitHub App and GitHub manual webhook: `pull_request` with `opened`,
    `synchronize`, `reopened` deploys; `closed` removes.
  - GitLab: `merge_request` with `open`, `reopen`, `update` deploys; `close`
    and `merge` remove.
  - Gitea and Forgejo: `pull_request` with `opened`, `synchronized`,
    `reopened` deploys; `closed` removes.
  - Bitbucket: `pullrequest:created` and `pullrequest:updated` deploy;
    `pullrequest:rejected` and `pullrequest:fulfilled` remove.
- The source of the pull request is fetched from the base repository:
  `refs/pull/<id>/head` (GitHub, Gitea) or `refs/merge-requests/<id>/head`
  (GitLab). Bitbucket has no such ref, so v4 checks out the commit.
- Forks: a pull request from another repository deploys only when previews of
  forks are on. GitHub compares the repository IDs of head and base. GitLab,
  Gitea, and Bitbucket compare the source and target project or repository
  IDs. Same-repository branches need write access, so they are trusted.
- With a GitHub App, a comment on the pull request shows the status and the
  link. Coolify deletes the comment when the pull request closes.
- When the pull request closes, Coolify cancels its deployments, stops its
  containers, and deletes its volumes and records. A volume of a preview is a
  new empty volume with a `-pr-<id>` suffix.

### Nodes now

- Every part of the Node pipeline keys on `node_workload_id`: builds,
  revisions, operations, containers, placement, domains, ingress routes, DNS
  names, rolling deploys, logs, and the terminal.
- A Git build stores its source on `node_builds`. Coolify resolves
  `refs/heads/<branch>` to a commit with `git ls-remote`. The builder fetches
  that commit by SHA (`git fetch --depth=1 origin <sha>`), not by branch.
- The v4 webhook endpoints start builds of cluster applications for push
  events only. Pull request events do not build cluster applications.
- `environment_variables()` of a workload already reads only rows with
  `is_preview = false`. No preview rows exist for workloads.
- A stack sets domains and variables without a user. It uses an owner or admin
  of the team (decision 0023).

## Decision

### Model

- A preview is a `NodeWorkload` with `preview_of_id` (the parent workload) and
  `pull_request_id`. The pair is unique.
- The preview also stores `pull_request_url`, `pull_request_ref` (for example
  `refs/pull/12/head`), `pull_request_from_fork`, and
  `pull_request_comment_id`.
- The parent stores the settings: `preview_deployments_enabled` (default off),
  `fork_preview_deployments_enabled` (default off),
  `preview_url_template` (default `{{pr_id}}.{{domain}}`), and
  `preview_uses_production_values` (default on). These are the v4 settings
  with the same defaults.
- Only a Git application can have previews. A database, a Docker image
  application, an inline Dockerfile, and a service of a stack cannot.
- A preview cannot have previews.

Reason: a child workload uses the full pipeline with no change. Build, deploy,
rolling deploys, ingress, certificates, DNS names, logs, terminal, and
rollback work because they already key on the workload. A separate model would
need a `pull_request_id` branch in each of these, as v4 has in its deployment
job. The cost of a child workload is that each list of workloads must hide
previews. That is a small, fixed set of queries.

### What a preview copies

At creation, a preview copies these values from its parent:

- the source: `source_type`, `git_repository`, `github_app_id` or
  `private_key_id`, `base_directory`, `dockerfile_location`, `build_target`;
- the project, environment, and team;
- the health check settings;
- the configuration of the latest revision of the parent: command, restart
  policy, CPU and memory limits, and files;
- the Node: the first Node of the parent. A preview runs on one Node;
- the outbound firewall rules of the parent (rules where the parent is the
  source), when the pull request is not from a fork. Coolify adds these
  allows from the parent link in each network revision. It does not store
  copies.

A redeploy of a preview (a new commit) copies the source, the build settings,
and the revision configuration again. Changes on the parent apply to the next
preview deploy.

A preview does not copy:

- the data of volumes;
- published ports, because a port and protocol map to one application in the
  cluster (decision 0016);
- scheduled tasks and volume backups;
- the domains of the parent. A preview gets generated domains only;
- inbound firewall rules (other workloads that call the parent);
- the manual webhook secrets. A preview is not a webhook target.

### Volumes

- A preview of an application with volumes gets a new empty volume for each
  volume of the parent, with the same mount path, on the Node of the
  preview. This is the v4 behavior.
- Coolify deletes these volumes with the preview. It does not back them up
  and does not copy them on a move.
- A preview with a writable volume deploys stop-then-start, as decision 0022
  says. A preview without volumes gets rolling deploys.

Reason: decision 0007 already pins a volume to one workload, so a new volume
for the child workload needs no new rule. A preview that shares the volume of
its parent would write production data from code that nobody reviewed. A
refusal would block previews for each application with a cache or upload
directory.

### Domains

- Coolify applies `preview_url_template` to each host of the parent routes.
  `{{domain}}` is the host, `{{pr_id}}` is the pull request number, and
  `{{random}}` is a random ID. Each route keeps its scheme, path, and port.
- When the parent has no domain, `{{domain}}` is
  `<parent uuid>.<generated domain base>` (`NodeCluster::generatedDomainBase`),
  so the default preview domain is `12.<parent uuid>.<base>`.
- Coolify sets the domains with `UpdateNodeWorkloadDomains`, as an owner or
  admin of the team when no user starts the preview (webhook). The uniqueness
  check of the team applies. When a domain is taken, the preview deploys
  without a domain and the deployment log says why.
- A custom domain of the parent needs a DNS record for the preview host, for
  example `*.example.com`. When the zone has managed DNS records (decision
  0019), Coolify adds the record.
- The user can change the domains of a preview on its page. A redeploy keeps
  them.

### Triggers

- Previews start only when `preview_deployments_enabled` is on. A close event
  always removes a preview, also when the setting is off.
- The events and actions are the v4 ones (see Context), for the GitHub App
  endpoint and the four manual webhook endpoints. The parent matches on its
  repository and on the base branch of the pull request. The secret checks
  are the v4 ones.
- The commit of a deploy is the head SHA of the payload. The build stores the
  pull request ref as its `git_branch`. A deploy without a commit (manual, or
  a rebuild) resolves `pull_request_ref` with `git ls-remote`.
- The refs: GitHub and Gitea `refs/pull/<id>/head`, GitLab
  `refs/merge-requests/<id>/head`. Bitbucket has no ref of a pull request, so
  a Bitbucket preview uses the source branch, and a Bitbucket pull request
  from a fork does not deploy.
- A new commit while a preview build runs cancels that build and starts a new
  one. A preview is not production, so only the newest commit matters.
- `[skip ci]` and `[skip cd]` in the title or the head commit skip the
  deploy, as in v4.
- Watch paths of the parent apply, as in v4.
- The parent page has a **Previews** tab. It lists the previews, and has
  **Deploy pull request** with a number. For a GitHub App source, it also
  lists the open pull requests. A manual deploy is the decision of the user,
  so it gets the values of a same-repository pull request, as in v4. The UI
  says this.
- Push webhooks never match a preview.

### Security

- A pull request from a fork deploys only when
  `fork_preview_deployments_enabled` is on. The fork checks are the v4 ones.
  An unknown head repository (for example a deleted fork) counts as a fork.
- A preview gets the preview variables of its parent. It also gets the
  production values for keys without a preview variable only when
  `preview_uses_production_values` is on and the pull request is not from a
  fork. This is the v4 rule.
- Build-time variables follow the same rule. A fork preview gets no
  production build secret. Secret build variables stay BuildKit secret mounts
  (decision 0012).
- A fork preview gets no outbound firewall rules of its parent, so it cannot
  reach the databases of the parent.
- The Git credential is the credential of the parent: a new GitHub App token
  or the deploy key. The builder sees it for the build time only (decision
  0011). The code of the pull request runs after the clone and cannot read the
  credential.
- Previews of forks share the BuildKit cache of the builder Node with
  production builds, as v4 shares the Docker build cache. The UI says this
  next to the fork setting.
- Access: a preview follows the access grants and the policy of its parent.
  A user who can view the parent can view its previews. A user who can deploy
  the parent can deploy and delete its previews. The settings need the role
  that changes the parent.

### Environment variables

- The parent has preview variables: rows with `is_preview = true`. The
  **Previews** tab edits them.
- At each preview deploy, Coolify writes the resolved set to the variables of
  the preview workload (rows with `is_preview = false` on the child). The
  build and the deploy then read them with no change.
- The preview page shows these variables read-only, with a link to the
  **Previews** tab of the parent.

### Cleanup

- A close event deletes the preview with `DeleteNodeWorkloadJob` and
  `deleteVolumes`. Its domains, DNS names, and routes leave every Node.
- The delete of a parent deletes its previews first.
- The number of previews of an application has no limit, as in v4. Each open
  pull request gets a preview while previews are on.
- For a GitHub App source, a daily job reads the state of the pull request of
  each preview and deletes the preview of a closed pull request. It is the
  safety net for a lost close event. Other sources have no API credential, so
  the user deletes such a preview on the **Previews** tab.
- With a GitHub App, Coolify adds a comment to the pull request with the status
  and the link, updates it for each deploy, and deletes it on close, as v4.

### Lists

A preview does not show in the resource list of an environment, the API list
of applications, the cluster map, or the dashboard counts. It shows on the
**Previews** tab of its parent and on the Node page.

### Stacks

Previews of stacks (Compose from Git) are not part of this decision. A stack
preview needs a copy of each service, a stack namespace, stack traffic, and
shared volumes for each pull request.

### Sentinel

No change. The builder fetches a commit by SHA, and the ref of a pull request
makes its head commit reachable in the base repository. Step 1 below
verifies this for GitHub, GitLab, Gitea, and Forgejo. If a provider refuses
the fetch, `image.build` gets an optional `git_ref` that the builder fetches
instead.

## Alternatives

- **A separate `NodeWorkloadPreview` model.** It keeps lists clean, but each
  part of the pipeline (builds, revisions, operations, containers, routes,
  DNS, logs, terminal) would need a second owner type. This is the v4 shape,
  with `pull_request_id` checks in each path.
- **Refuse previews of applications with volumes.** This is simpler, but it
  blocks many applications that only keep a cache or uploads.
- **Share the volume of the parent.** The code of a pull request would write
  production data.
- **Previews of stacks in this step.** They need a stack copy for each pull
  request. Stacks from Git are new (decision 0023, step 2).

## Consequences

- A preview has every feature of a cluster application: logs, terminal,
  rolling deploys, rollback, and moves.
- A preview uses CPU, memory, and disk on the first Node of its parent.
  The number of previews has no limit, so many open pull requests can fill
  the Node. The user turns previews off or closes pull requests.
- Each preview domain gets its own ACME certificate. Many previews on one
  custom domain can reach the rate limits of the ACME issuer.
- Each query that lists workloads must hide previews.
- A Bitbucket pull request from a fork cannot have a preview.

## Not decided here

- Previews of stacks.
- Previews on more than one Node.
- A copy of the data of a volume into a preview (for example a database
  snapshot).
- Preview comments for GitLab, Gitea, and Bitbucket.

## Implementation (2026-11-15)

Built: steps 2 to 13. `PullRequestWebhook` (`app/Support/`) maps the actions of
each provider to a deploy or a close. `StartsClusterWebhookBuilds` deploys or
removes the previews, and each webhook response has one entry for each matched
application, with its status and the reason of a skip. The **Previews** tab is
`Project\ClusterApplication\Previews`. The comment job is
`UpdateNodePreviewCommentJob`, and the daily job is `RemoveClosedNodePreviewsJob`.

Events of each endpoint:

| Endpoint | Deploy | Close | Ref | Fork check |
| --- | --- | --- | --- | --- |
| GitHub App, GitHub manual | `pull_request`: `opened`, `synchronize`, `reopened` | `closed` | `refs/pull/<id>/head` | head and base repository IDs (v4 `isForkPullRequest`) |
| GitLab manual | `merge_request`: `open`, `opened`, `reopen`, `reopened`, `synchronize`, `update` | `close`, `closed`, `merge` | `refs/merge-requests/<id>/head` | source, target, and webhook project IDs |
| Gitea and Forgejo manual (`/webhooks/source/gitea/events/manual`) | `pull_request`: `opened`, `synchronized`, `reopened` | `closed` | `refs/pull/<id>/head` | head, base, and webhook repository IDs |
| Bitbucket manual | `pullrequest:created`, `pullrequest:updated` | `pullrequest:rejected`, `pullrequest:fulfilled` | the source branch | source, destination, and webhook repository UUIDs |

These points differ from the decision above or add to it:

- **Watch paths.** A cluster application has no watch paths. Push webhooks do
  not check them either. Previews deploy for each commit.
- **Skip markers.** The title skips a deploy for each provider. The head commit
  message also skips it for GitHub App (an API call) and GitLab (from the
  payload), as in v4. Gitea and Bitbucket send no commit message.
- **Unknown repositories.** An unknown head repository counts as a fork. When
  the base repository is not the repository of the webhook, the preview does not
  deploy, also with previews of forks on. This is the v4 check.
- **Ignored actions.** An action that does not deploy or close (for example
  `labeled`) gets "Nothing to do" without a secret check when no v4 application
  matches. It does not count as a failed secret.
- **Manual deploy.** **Deploy pull request** uses `refs/merge-requests/<id>/head`
  when the host of the repository contains `gitlab`, else `refs/pull/<id>/head`.
  A self-hosted GitLab on another host name needs the webhook. Bitbucket refuses
  a manual deploy, because it has no ref of a pull request. A manual deploy
  keeps the pull request URL from an earlier webhook.
- **Open pull requests.** The **Previews** tab does not list the open pull
  requests of a GitHub App source yet.
- **Preview variables.** The **Environment Variables** tab of the parent shows
  them as v4 shows them for an application: a **Production** and a **Preview
  deployments** group, the environment filter, and the second textarea in the
  developer view. It shows them for every Git cluster application, also with
  previews off, as v4 does. v4 copies each new production variable to the
  previews; a cluster application does not, so the add modal has an
  **Environment** listbox to add a preview variable. `previewEnvironmentVariables()`
  is now `environment_variables_preview()`, the v4 name.
- **Previews tab.** It follows the v4 layout: the toggle button, **PR deployment
  access**, **Production values** in the place of the v4 **New preview
  variables** listbox, the URL template, a manual deploy by number, and one card
  per preview with an editable **Domains** field and the **Links**, **Logs**, and
  **Actions** menus (Redeploy, Start, Stop, Delete). A deploy sets the domains
  only when it creates the preview, so an edited domain stays. Builds have no
  no-cache option, so there is no **Force deploy without cache**.
- **Preview page.** A preview page shows a note with links to the parent and to
  the pull request. Its **Environment Variables** tab lists the names only and
  is read-only. Its **Webhooks** tab is hidden. The other tabs stay editable,
  but the next deploy of the pull request copies the settings of the parent
  again, except the domains.
- **Comment.** The comment job checks the build and its deploy each 15 seconds
  for up to one hour, and writes the comment when the status changes. A removed
  preview deletes its comment, also when the user deletes it on the
  **Previews** tab.
- **Daily job.** It runs only when Nodes are in use. An API error keeps the
  preview.

Verified on dev (Gitea 1.26, manual webhook, one Node): a push to the branch of
pull request 2 built and served the preview at
`https://2.<parent uuid>.<base>` with the new commit; a close and reopen of the
fork pull request 1 with previews of forks off was skipped with the reason;
with them on, it built without the production values; a close removed the
preview with its volumes; **Deploy pull request** of the fork pull request got
the production values; a delete on the **Previews** tab removed the preview;
with previews off, a reopen was skipped with the reason. Not verified live: the
GitHub App and GitHub, GitLab, and Bitbucket endpoints (code reading only), the
GitHub comment (its body and status were checked with a local call, not
against GitHub), and the daily job.

## Build steps

1. **Fetch check.** On the dev Gitea, Forgejo, a GitHub repository, and a
   GitLab project, open a pull request from a fork. Run the builder fetch
   (`git fetch --depth=1 origin <head sha>`) on the base repository. Record
   the result here. Only if a provider refuses: add `git_ref` to
   `image.build` in Sentinel.
   Result (2026-11-15): every provider sends the head commit of a pull request
   from a fork by SHA from the base repository. Gitea 1.26 and Forgejo 17 (dev,
   private repository) also have `refs/pull/<id>/head`. GitHub
   (`coollabsio/coolify` PR 12310) and GitLab (`gitlab-org/gitlab-runner` MR
   7847, also `refs/merge-requests/<id>/head`) were checked with public
   pull requests. No Sentinel change is needed.
2. **Schema and model.** Migration: `preview_of_id`, `pull_request_id`,
   `pull_request_url`, `pull_request_ref`, `pull_request_from_fork`,
   `pull_request_comment_id` on the preview, the four settings on the parent,
   and a unique index on (`preview_of_id`, `pull_request_id`).
   `NodeWorkload::previewOf()`, `previews()`, `isPreview()`. Files:
   `database/migrations/`, `app/Models/NodeWorkload.php`,
   `database/factories/NodeWorkloadFactory.php`.
3. **Hide previews.** Add `whereNull('preview_of_id')` to the resource list,
   the API list, the cluster map, the project and environment counts, and
   `clusterWebhookWorkloads`. Files: `app/Livewire/Project/Resource/Index.php`,
   `app/Http/Controllers/Api/ClusterApplicationsController.php`,
   `app/Livewire/Dashboard/ClusterMap.php`, `app/Models/Project.php`,
   `app/Models/Environment.php`,
   `app/Http/Controllers/Webhook/Concerns/StartsClusterWebhookBuilds.php`.
4. **Access.** A preview resolves policies and access grants through its
   parent. Files: `app/Policies/NodeWorkloadPolicy.php`,
   `app/Services/Access/AccessResolver.php`.
5. **Refs.** `ResolveGitCommit` accepts a full ref (`refs/...`).
   `StartNodeBuild` stores `pull_request_ref` as the build `git_branch` for a
   preview. Files: `app/Actions/Node/Build/ResolveGitCommit.php`,
   `app/Actions/Node/Build/StartNodeBuild.php`,
   `app/Traits/BuildsNodeWorkloadImage.php`.
6. **Create or update a preview.** New `DeployNodePreview` action: find or
   create the child with the copied values, the Node, empty volumes, the
   domains from the template, and the resolved variables. Cancel an active
   build, then start a build at the commit. The first
   revision configuration of a preview is the latest one of the parent.
   Files: `app/Actions/Node/DeployNodePreview.php`,
   `app/Models/NodeWorkload.php` (`firstRevisionConfiguration`).
7. **Variables.** Preview variables on the parent and the resolve rule
   (production values, forks). Files: `app/Models/NodeWorkload.php`,
   `app/Actions/Node/DeployNodePreview.php`.
8. **Firewall.** The network revision adds the outbound allows of the parent
   for each preview that is not from a fork. File: the builder of the
   cluster firewall rules in `app/Actions/Node/`.
9. **Remove a preview.** New `RemoveNodePreview` action: cancel the build and
   dispatch `DeleteNodeWorkloadJob` with `deleteVolumes`. The delete of a
   parent removes its previews first. Files:
   `app/Actions/Node/RemoveNodePreview.php`,
   `app/Jobs/DeleteNodeWorkloadJob.php`.
10. **Webhooks.** Pull request events of the GitHub App and the four manual
    endpoints call steps 6 and 9, with the v4 fork checks and skip rules.
    Files: `app/Http/Controllers/Webhook/Github.php`, `Gitlab.php`,
    `Gitea.php`, `Bitbucket.php`,
    `app/Http/Controllers/Webhook/Concerns/StartsClusterWebhookBuilds.php`.
11. **UI.** The **Previews** tab on the parent (settings, URL template, manual
    deploy, preview cards), preview variables on its **Environment Variables** tab, and a link to the parent on the
    page of a preview. Files: `app/Livewire/Project/ClusterApplication/`,
    `resources/views/livewire/project/cluster-application/`, `routes/web.php`.
12. **GitHub App.** Pull request comments, the list of open pull requests, and
    the daily cleanup job. Files: a new job in `app/Jobs/`,
    `app/Console/Kernel.php`.
13. **Docs and roadmap.** Mark L5 in `docs/v5/roadmap.md`, and add a change
    file.
