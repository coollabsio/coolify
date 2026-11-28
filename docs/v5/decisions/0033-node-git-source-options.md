# Decision 0033: Watch paths, submodules, and LFS of cluster applications

**Status: Accepted**

**Date: 2026-11-23**

## Context

Decision 0011 leaves Git LFS and submodules open. Cluster applications also
have no watch paths. This decision closes these gaps.

### v4

- `applications.watch_paths` holds one glob for each line. A line that starts
  with `!` excludes. The last pattern that matches wins
  (`Application::matchPaths`, `globMatch`). `parseWatchPaths` removes a
  leading `/`.
- Push webhooks read the changed files from `commits[].added`, `removed`, and
  `modified` (`ReadsWebhookPushPayload::webhookPushChangedFiles`). Without a
  commit list the files are unknown, and the push deploys
  (`webhookPushMatchesWatchPaths`). GitHub, GitLab, and Gitea use this rule.
  The v4 Bitbucket controller does not check watch paths.
- A GitHub App push uses the compare API (`githubPushComparedFiles`,
  `getGithubCommitRangeFiles`). The API returns at most 300 files, so 300
  files or a new branch (`before` is all zeros) give "unknown".
- Only GitHub App previews check watch paths
  (`ProcessGithubPullRequestWebhook`): `synchronize` compares `before` and
  `after`, and `opened` and `reopened` read the files of the pull request
  (`getGithubPullRequestFiles`, at most 3000). "Unknown" deploys.
- `application_settings` has `is_git_submodules_enabled`,
  `is_git_lfs_enabled`, and `is_git_shallow_clone_enabled` (all default
  `true`). `Application::generateGitImportCommands` and
  `setGitImportSettings` use them: `--depth=1`, `--recurse-submodules
  --shallow-submodules`, `submodule update --init --recursive`,
  `GIT_LFS_SKIP_SMUDGE=1` when LFS is off, and `git lfs pull` only when
  `git lfs ls-files` lists a file. A failed `git lfs pull` fails the
  deployment.

### v5 today

- `node_workloads` has no watch paths and no Git options. The webhook concern
  `StartsClusterWebhookBuilds` starts a build for each matched workload
  (`startClusterWebhookBuild`) and a preview for each pull request event
  (`handleClusterPullRequest`). Neither reads changed files. For a GitHub App
  push, `Github.php` calls `startGithubAppClusterBuilds` before the v4 compare.
- The builder (`scripts/builder-entrypoint.sh` in Sentinel, image
  `Dockerfile.builder` with `git` and `git-lfs`) always does this:
  - `git fetch --depth=1 origin <commit>` with `protocol.file.allow=never`,
    then a checkout of `FETCH_HEAD`. History is always shallow.
  - When `.gitmodules` exists: `submodule update --init --recursive
    --depth=1`, also with `protocol.file.allow=never`. Submodules are always
    on.
  - `git lfs pull 2>/dev/null || true`. LFS is always on, and an error is
    hidden. A build can continue with LFS pointer files. LFS files of
    submodules are not pulled.
- `ImageBuildRequest` (`crates/protocol/proto/control.proto`, fields 1 to 20)
  has no Git option. `IssueNodeGitCredential` sends one credential: a GitHub
  App installation token for the whole installation (`generateGithubToken`
  asks for no repository list), or the deploy key. The builder gives the
  token only to the host of `repository_url` (a Git credential helper). It
  gives the deploy key to every SSH host (`GIT_SSH_COMMAND`).
- A preview build uses the credential of its parent (decision 0025,
  `BuildsNodeWorkloadImage`), also for a pull request from a fork. Until
  2026-11-23, the builder gave this credential also to the submodules and LFS
  objects of a fork. This is fixed (see "Security", "Fixed before this
  decision").

### The contradiction in decision 0025

The "Triggers" section of 0025 says: "Watch paths of the parent apply, as in
v4." The implementation notes of 0025 say: "A cluster application has no
watch paths. Push webhooks do not check them either. Previews deploy for each
commit." The code follows the notes.

## Decision

### Git options

- Add `is_git_submodules_enabled`, `is_git_lfs_enabled`, and
  `is_git_shallow_clone_enabled` to `node_workloads`. The default of each is
  `true`, as in v4. These defaults keep the behavior of today.
- Show them on the **Git source** settings of a cluster application with the
  v4 labels. The API (decision 0029) uses the v4 field names.
- A preview uses the values of its parent at build time.
- Add three fields to `ImageBuildRequest` under one capability,
  `image.build.git_options.v1`. A proto3 `bool` is `false` when a sender does
  not set it, so each field names the change from today:

| Field | Builder |
| --- | --- |
| `bool skip_submodules = 23` | No submodule step |
| `bool skip_lfs = 24` | `GIT_LFS_SKIP_SMUDGE=1`, no `git lfs pull` |
| `bool full_history = 25` | `git fetch origin <commit>` without `--depth`; submodules without `--depth=1` |
| `bool untrusted_source = 21` | Built already, with its own capability `image.build.untrusted_source.v1` (see "Security") |

Field 22 is `max_concurrent_builds` of decision 0035, so the Git options use 23
to 25 (see "Implementation notes").

- Coolify sends a field only when it is `true`. A build with a field set
  needs the capability on the builder Node. Else the build fails with "Upgrade
  Sentinel on the builder server."
- With LFS on, the builder runs `git lfs pull` only when `git lfs ls-files
  -n` lists a file, as v4. A failed pull fails the build. The builder does not
  hide the error anymore.
- Submodules accept only `https` and `ssh` URLs
  (`protocol.allow=never`, `protocol.https.allow=always`,
  `protocol.ssh.allow=always`). Today only `file` is refused.
- One build has one credential. A relative submodule URL (`../lib.git`)
  resolves to the repository host and gets the credential. A submodule on a
  different host must be public. For a GitHub App, the installation must
  include the repository of the submodule.

### Watch paths

- Add `watch_paths` to `node_workloads`, with the v4 format and the v4
  normalization. Show it on the **Webhooks** tab. One list applies to pushes
  and to previews, as in v4.
- Move the matcher of `Application` (`matchPaths`, `globMatch`, and the
  normalization) to one shared class. v4 and v5 call the same code.
- A webhook skips a build only when Coolify knows every changed file and no
  file matches. When the files are unknown, the build starts. The response
  says "Changed files do not match watch paths."
- Manual deploys, API deploys, rollbacks, and **Deploy pull request** ignore
  watch paths.

Sources of the changed files:

| Event | Provider | Source | Unknown when |
| --- | --- | --- | --- |
| Push | GitHub App | compare API `before...after` | 300 files or more, a new branch, an API error |
| Push | GitHub, Gitea manual | `commits[]` of the payload | no list, a new branch, `forced` is true (GitHub), fewer commits than `total_commits` (Gitea) |
| Push | GitLab manual | `commits[]` | no list, a new branch, `total_commits_count` larger than the list (GitLab sends at most 20 commits) |
| Push | Bitbucket manual | none | always |
| `synchronize` | GitHub App | compare API `before...after` | as for a push |
| `opened`, `reopened` | GitHub App | pull request files API | 3000 files or more, an API error |
| Pull request | GitHub, GitLab, Gitea, Bitbucket manual | none | always (the payload has no file list, and Coolify has no API credential) |

- Coolify reads the files once for each webhook and passes them to
  `startClusterWebhookBuild` and `handleClusterPullRequest`. For a GitHub App
  push, the compare runs when a v4 application or a cluster application of
  the push has watch paths.
- `PullRequestWebhook` gets `before` and `after` for GitHub `synchronize`.
- A Git stack service has no watch paths in this decision. Its webhook deploys
  the stack.

**This decision replaces the text of 0025 about watch paths:** The watch paths
of the parent apply to its previews. A preview event skips the build only when
Coolify knows every changed file of the event and no file matches. Today only
the GitHub App gives these files. For the four manual webhooks, each preview
event deploys. A close event always removes the preview.

## Options compared

Git options:

| Option | Effect | Result |
| --- | --- | --- |
| A. Always on (today) | No settings. Fork previews fetch submodules and LFS with the credential | Rejected: the fork gap stays, and a repository with a broken submodule cannot build |
| B. Three toggles in the build request (chosen) | v4 settings, default on | Chosen |
| C. Off by default | Smaller clones | Rejected: v4 defaults are on, and users expect the same build |

Watch paths:

| Option | How Coolify knows the files | Result |
| --- | --- | --- |
| A. No watch paths (today) | — | Rejected: v4 users lose a setting, and monorepos build each service on each push |
| B. Provider lists, "unknown" deploys (chosen) | Payload or GitHub API, as v4 | Chosen: same code and rule as v4, no extra fetch |
| C. Git diff in the Coolify container | A blobless fetch of both commits | Rejected: a fetch for each webhook, and Git work on untrusted repositories in the Coolify container |
| D. Git diff in the builder | The builder exits early | Rejected: the build is already queued and holds a builder slot; the result is "skipped" only after a fetch |

## Security

- **Fixed before this decision: fork previews and the credential.** A pull
  request from a fork controls `.gitmodules` and `.lfsconfig`. Until
  2026-11-23, the builder ran the submodule and LFS steps with the credential
  of the parent: the HTTPS token answered for every repository on the host
  of the repository, and the deploy key went to every SSH host. So the
  content of a fork decided which repositories the credential read. This
  crossed the boundary "no login -> team data", when a team admin turned on
  `fork_preview_deployments_enabled`. v4 has the same gap: its clone of a
  pull request rewrites every HTTPS URL of the host to the token
  (`url.<host>.insteadOf` in `Application::generateGitImportCommands`), also
  for a fork.
- The fix (Sentinel `57e889c`, Coolify on `v5-progress`): Coolify sets
  `untrusted_source` for each build of a fork preview of a private
  repository (`NodeWorkload::buildsUntrustedSource()`). The HTTPS token then
  answers only for the path of the repository, with and without `.git`, which
  includes its LFS endpoint. The deploy key is used only for the fetch of the
  commit. Public submodules still work. A private submodule of a fork preview
  fails with the Git error, and the LFS objects of a fork preview of an SSH
  repository are not pulled. Such a build needs
  `image.build.untrusted_source.v1` on the builder Node, and Flux refuses the
  field for a Sentinel without it.
- **Local paths.** `protocol.file.allow=never` refuses a submodule with a
  local path today. The `https`/`ssh` allow-list also refuses `git://`,
  `http://`, and `ext::`.
- **Another team.** A GitHub App or a deploy key belongs to one team, or the
  App is system-wide (`IssueNodeGitCredential::githubApps`). A submodule can
  read only what the credential reads. A team that uses a system-wide App can
  already select each repository of the installation as its
  `git_repository`. So a submodule adds no access to another team. No option
  crosses the team boundary.
- **The Node host.** The submodule and LFS steps run in the one-shot build
  container (`--cap-drop all`, `no-new-privileges`, user 1000). No option
  runs them on the host. A member with write access on the application
  already has root on its Nodes, so the toggles and watch paths need the
  `update` policy of the workload only.
- **The Coolify host.** Option C runs Git on untrusted repositories in the
  Coolify container. For a fork preview, it needs no role. This is a reason
  to reject C. Options A, B, and D keep Git work on the builder Node.
- The GitHub token stays valid for at most one hour, and the builder deletes
  it after the build (decision 0011). A deploy key does not expire, so the
  fork rule matters most for it.

## Consequences

- v4 users get the same three Git settings and the same watch paths.
- One Sentinel version adds the four fields and the error for LFS. A builder
  Node needs one upgrade.
- An LFS error now fails the build instead of a build with pointer files.
- With the four manual webhooks, previews deploy for each commit, as today.
  Only the GitHub App can skip a preview build.
- A fork preview with a private submodule fails. The UI shows the Git error.
  This is already so since the fix of 2026-11-23.
- The shared matcher changes v4 code. The v4 tests of `matchPaths` must keep
  passing.

## Answers to the open questions (2026-11-26)

The user accepted the decision with these answers. They have priority over
the text above.

- **Git stacks.** A Git stack gets the same Git options (submodules, LFS,
  shallow clone) as a cluster application.
- **LFS failure.** An LFS failure fails the build. A build that passes today
  with pointer files is already broken, so the error shows it.

## Not decided here

- Watch paths of a Git stack, and of each service of a stack.
- A second credential for a submodule on another host.
- LFS files inside submodules (v4 does not pull them either).
- The file list of GitLab and Gitea pull requests through their APIs.
- The fix of the same fork gap in v4.
- `StrictHostKeyChecking=no` of the deploy key in the builder.

## Implementation notes (2026-11-28)

Built in Coolify and in Sentinel `db2612c` (`image.build.git_options.v1`), on
top of the fork credential fix (Sentinel `57e889c`).

### Changes to the decision

- **Field numbers 23 to 25.** Decision 0035 took field 22 for
  `max_concurrent_builds` before this decision was built.
- **The checkout always keeps the LFS pointer files.** The builder sets
  `GIT_LFS_SKIP_SMUDGE=1` for the fetch, the checkout, and the submodules, also
  when LFS is on. The LFS step then runs `git lfs pull` as its own step, so
  that its error shows in the build log under "Fetching Git LFS objects". The
  result with LFS on and off is the same as in the table.
- **The LFS objects of an untrusted SSH source are not pulled, with a log
  line.** The deploy key fetches only the commit (fix of 2026-11-23), so
  `git lfs pull` of a fork preview from an SSH repository cannot
  authenticate. With the new LFS failure, such a build would fail each time.
  The builder skips the step and logs "Skipping Git LFS objects (the source is
  not trusted, and the SSH key fetches only the commit)". An untrusted HTTPS
  source pulls LFS objects, because the token answers for the LFS endpoint of
  the repository.
- **A stack service and a preview have no Git options of their own.** The
  build reads the options of the stack or of the parent
  (`NodeWorkload::gitOptionBuildFields()`). The settings and the API refuse
  them on a preview or a stack service. The API lists the stored values of the
  workload.
- **"Unknown" for GitHub manual pushes also includes `created`.** A new branch
  is unknown for each provider: `before` is all zeros, or GitHub sets
  `created`.

### Coolify

- Migration `2026_11_28_140000`: `is_git_submodules_enabled`,
  `is_git_lfs_enabled`, and `is_git_shallow_clone_enabled` (default `true`) on
  `node_workloads` and `node_stacks`, and `node_workloads.watch_paths`.
- `NodeWorkload::gitOptionBuildFields()` gives the `image.build` fields of the
  options that are off. `RunImageBuild` sends them. `buildCapabilities()`
  adds `image.build.git_options.v1` only when a field is set, so
  `SelectBuilderNode` gives the "Upgrade Sentinel" reason only for a build
  with an option off. `IssueFluxCredential` grants the capability.
- `UpdateNodeWorkloadSettings::gitOptions()` (application or stack) and
  `watchPaths()`. The **Git** subsection with the v4 labels is on the General
  page of a Git application (not of a preview or a stack service) and of a Git
  stack. **Watch paths** is in the **Deployment** subsection of the
  **Webhooks** tab.
- API: `PATCH /cluster-applications/{uuid}` accepts `watch_paths` and the 3
  options, and `PATCH /cluster-stacks/{uuid}` the 3 options. The responses
  show them. Both need the `update` policy, like the other settings.
- `App\Support\WatchPaths` is the shared matcher (`normalize`, `triggers`,
  `matchPaths`, `globMatch`). `Application` uses it for its `watch_paths`
  setter and `isWatchPathsTriggered()`. The old static methods of
  `Application` are removed, and `ApplicationWatchPathsTest` calls
  `WatchPaths::matchPaths`.
- `StartsClusterWebhookBuilds`:
  - `clusterPushChangedFiles()` reads the files of a manual push with the
    rules of the table. Bitbucket is always unknown.
  - A GitHub App push reads the compare API once for the webhook
    (`onceOnly`), only when a v4 or a cluster application has watch paths.
  - A GitHub App preview reads the compare API for `synchronize` (`before`
    and `after` are now in `PullRequestWebhook`) and the files of the pull
    request for `opened` and `reopened`.
  - A skip gives `status: skipped` and "Changed files do not match watch
    paths." and creates no build row. A stack service has no watch paths.

### Sentinel `db2612c`

- `ImageBuildRequest` fields 23 to 25, the capability, the Flux mapping, and
  `--skip-submodules`, `--skip-lfs`, and `--full-history` for the builder.
- `builder-entrypoint.sh`: the `https`/`ssh` allow-list
  (`protocol.allow=never`) for the fetch, the submodules, and LFS; the depth
  in the step line ("depth 1" or "full history"); "Skipping submodules (turned
  off)"; and `git lfs pull` only when `git lfs ls-files -n` lists a file,
  without `2>/dev/null || true`.
- Unit tests with a fake `git`: the arguments of each option, the steps with
  and without the options, and the LFS skip for an untrusted SSH source.

### Verified on dev (2026-11-28)

With Nodes 1 and 2 on `sentinel-host` `main-db2612c`, the Flux override of
`main-db2612c`, and private repositories on the local Gitea over SSH with a
deploy key (a submodule `../g33-sub.git`, an LFS file, LFS turned on for the
test):

| Check | Result |
| --- | --- |
| Defaults | "(depth 1)", "Fetching submodules (depth 1)", "Fetching Git LFS objects"; the build saw the submodule file, the real LFS content, and a shallow repository |
| All 3 options off | "(full history)", "Skipping submodules (turned off)", "Skipping Git LFS objects (turned off)"; no submodule file, the LFS pointer file, no `.git/shallow` |
| A missing LFS object | The build failed: `[961ecc…] Not Found: [404] Not Found`, "Failed to fetch some objects" |
| A fork preview of the SSH repository (submodules off on the parent) | The preview took `skip_submodules` from the parent, and the builder logged the untrusted LFS skip; the build passed with the pointer file |
| Watch paths `app/**` and `!app/skip.txt` from the Gitea webhook | A push of `docs/readme.md` and a push of `app/skip.txt` gave "Changed files do not match watch paths." and no build row; a push of `app/index.html` queued a build |
| A Git stack with LFS off and a full history | The service build logged "(full history)", "Fetching submodules (full history)", and "Skipping Git LFS objects (turned off)" |
| API | A read token of a member: `403` on `PATCH`, and the fields on `GET`. A write token of a member: `403`. A token of another team: `404` for the application and the stack. An admin token: `200`, normalized `watch_paths`; `422` for a value that is not a boolean |
| Pages | As in v4: the **Deployment** and **Git** subsections on the **Advanced** page of a Git application (not of a preview or a stack service), and **Watch paths** on the **General** page below **Base directory**, saved with the other build settings. A Git stack has the **Git** subsection on its **General** page, because the v4 service menu has no **Advanced** page |

The GitHub App compare and pull request file paths, and the GitLab, GitHub,
and Bitbucket manual payloads, are checked by code reading only.

