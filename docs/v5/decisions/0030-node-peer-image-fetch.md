# Decision 0030: Copy local images between Nodes without a registry

**Status: Accepted**

**Date: 2026-11-17**

## Context

Before this decision, a cluster stored its builds in one of three image
storages (decision 0006):

- `EXTERNAL`: a registry of the team, for example GHCR or Docker Hub;
- `BUILT_IN`: zot on one Node of the cluster (removed by this decision);
- `LOCAL`: the Podman store of the only Node (`image.load.v1`), as
  `localhost/<workload>:<build>`.

A local image is only on the Node that built or loaded it. A move to another
Node (`CreateMoveOperation`, `MoveNodeWorkloadJob`) needs the image on the
target. When the second Node joined, the user had to choose a registry, and
builds failed until the user chose.

The built-in registry kept a second copy of each image, it was one point for
all pulls, and it lost its images when it moved to another Node (decision
0017).

We compared these models:

- **Uncloud** runs unregistry on each machine. unregistry serves and receives
  images in the containerd image store of Docker. `uc image push` pushes a
  local image to all machines or to the selected machines, and only the
  missing layers move. Uncloud does not pull images from other machines: the
  `missing` pull policy has the TODO "make each machine aware of the images on
  other machines and it possible to pull from them" (`pkg/api/service.go`,
  commit `00120d4`). Each image is on each machine that the user pushes to,
  also when the machine never runs it.
- **Spegel** (Kubernetes) is a registry mirror on each Node that serves the
  layers of the containerd store of that Node to the other Nodes.
- Both work because containerd keeps each layer as the **original compressed
  blob**, with the digest of the registry. A peer can act as a normal registry
  mirror.
- **Podman** (`containers-storage`) unpacks each layer and does not keep the
  compressed blob. A peer cannot serve the blob with its registry digest, so a
  `registries.conf` mirror that points to a peer fails the digest check.
- **zot replicas** with the sync extension give a second registry copy. This is
  more registry state, not less. (History: Coolify removed zot with this
  decision.)
- **`podman save | podman load` as one stream** always sends the full image.

Coolify already knows on which Nodes each revision ran, and it controls each
deploy and move. A Node does not need to discover the images of the other
Nodes itself.

## Decision

### Fetch a local image from a peer

A Node that must run a local image that it does not have fetches the image
from another Node of the cluster that has it. Coolify selects the source and
starts the transfer. Only the layers that the target does not have move.

- The source exports the image with
  `podman save --format oci-dir --uncompressed <image-id>` into a directory of
  the transfer. The export has the original config and uncompressed layers. The
  digest of each layer is its DiffID.
- The source serves that one image read-only with the OCI distribution API
  (`GET` and `HEAD` of `/v2/`) on its WireGuard address.
- The target runs `podman pull` from the source. Podman skips each layer that
  it already has, because the uncompressed digests match.
- The target tags the image with its original reference
  (`localhost/<workload>:<build>`), removes the transfer reference, and checks
  that the image ID is the same as on the source.
- The **image ID and the local reference stay the same**. The manifest digest
  changes, but a local image has no registry digest, so nothing refers to it.

### Sentinel commands

Two commands, built like the volume transfer (decision 0007,
`volume_transfer.rs`):

- `image.serve.v1` on the source. Input: the image, the target Node address,
  a transfer ID, a secret, and an expiry. It exports the image, starts the
  server on a port from the transfer range 7800-7899 (shared with the volume
  transfer), adds the element `<target_ip> . <port>` to the transfer set of
  the `coolify_cluster` table, and returns the address, the port, the
  repository, the manifest digest, and the image ID. It removes the export,
  the server, and the element on `stop`, at the expiry, or when Sentinel
  restarts.
- `image.fetch.v1` on the target. Input: the source address, the port, the
  repository, the digest, the secret, the transfer ID, the original reference,
  and the image ID. It runs asynchronously: it pulls from the source, checks
  the image ID, then tags the local reference. Coolify polls it for the state.

Both keep their state in memory only. The secret is never journaled. On start,
Sentinel removes the exports, servers, and firewall elements that an earlier
process left behind.

### Where Coolify uses it

- **Every deploy path.** `FetchNodeImage` runs once in `DeployNodeWorkloadJob`,
  before Sentinel gets the revision. A deploy, a move, a rollback, a stack
  deploy, a preview, and a reconcile all use this job, so all of them get the
  image when the Node does not have it.
- **Sources.** The sources are, in this order: the Node that built the image,
  then the Nodes that run or keep the image. Coolify finds them by the image,
  not by the revision ID, so a rollback revision that reuses an image finds
  the Nodes of the earlier revision. Coolify tries the next source when a
  fetch fails or the source is offline. It always stops the serve of a
  source, also after a failure.
- **Move.** A move of a revision with a local image needs `image.serve.v1` on
  the source and `image.fetch.v1` on the target. Without them, or when the
  source is offline, the move is blocked with the reason, for example "The
  image is only on <node>, which is offline. Deploy the application again,
  then recover it."
- **Rollback.** A local image deploys when a Node still keeps it. Coolify
  fetches it from that Node. When no Node has it, Coolify builds the same
  commit again (decision 0017).
- **Deploy log.** After a fetch, the deploy log shows "The image was copied
  from server <node>."
- **Order of sources for a Node:** the local store, then a peer (local images
  only), then the registry of the revision, then a new build or a clear error.

### Image storage of a cluster

- `NodeImageStorage` has two values: `LOCAL` and `EXTERNAL`.
- `LOCAL` is a stored choice of the cluster. It is the default for a cluster
  with two or more Nodes when every Node reports `image.load.v1`,
  `image.serve.v1`, and `image.fetch.v1`. Each build loads its image into the
  Podman store of its build Node (`image.load.v1`), and other Nodes fetch it
  from there.
- `EXTERNAL` pushes each build to the registry of the team, as before.
- Builds do not fail while no choice is made.
- The onboarding of the second server shows the choice one time: "On the
  servers (recommended)" or "External registry".
- Images from a registry (an `EXTERNAL` build or an image source such as
  `nginx:1.27`) always come from their registry by digest (decision 0017).
  Coolify does not fetch them from a peer, because the peer copy has a
  different manifest digest and the revision refers to the registry digest.

### Removal of the built-in registry

The product owner decided to remove the built-in zot registry completely.
These parts are removed:

- the `BUILT_IN` image storage;
- the Sentinel commands `registry.reconcile.v1` and `registry.inspect.v1`;
- the `node_registries` table (migration
  `2026_11_17_120000_drop_node_registries_table`, which also sets the stored
  `built_in` choice to null);
- the registry DNS name `registry.cluster.coolify.internal:5443` and the
  firewall rules of port 5443;
- the registry CA and the push login of the builder. `builder.reconcile.v1`
  has only `enabled` and `max_used_space_bytes`;
- the registry section of the cluster **Advanced** page;
- `registry_server_uuid` in the API.

On start, Sentinel removes a `coolify-registry` container, its volume, and its
files when they remain from an earlier version. The revisions of a cluster
that used zot refer to an address that no longer resolves. Deploy these
applications again. v5 is not released, so Coolify does not migrate these
images.

### Security

- All Nodes of a cluster belong to one team, and all of them run the
  workloads of that team. A fetch between Nodes of one cluster crosses no new
  boundary.
- The server listens only on the WireGuard address, and only the target of the
  transfer can reach it (nftables element). It never listens on a public
  address.
- Each transfer has its own secret from Coolify. The server uses HTTP Basic
  auth with the user `coolify` and this secret, and refuses requests without
  it. The traffic is plain HTTP inside the WireGuard tunnel.
- The server serves only the image of the transfer. It never serves other
  images of the store or the build cache.
- Coolify accepts a source and a target only when both are active Nodes of the
  cluster of the revision.

### Cleanup

- The image cleanup (decision 0027) keeps the images of the deployed revision
  and of the last 2 earlier revisions of each workload on each Node. A rollback
  or a move can therefore fetch these images from the Nodes that ran them.
- A build Node also keeps the last 3 successful local builds of each
  workload, also when no workload runs on it.
- The export directory of a transfer is temporary and is removed when the
  transfer ends.

### Capabilities

- Coolify fetches from a peer only when the source reports `image.serve.v1`
  and the target reports `image.fetch.v1`.
- Without them, Coolify does not copy a local image. A move of a local image
  is blocked with the reason, and a deploy on a Node without the image fails
  with the reason.
- `LOCAL` is the default for a cluster with two or more Nodes only when every
  Node reports `image.load.v1`, `image.serve.v1`, and `image.fetch.v1`.

## Implementation

- **Coolify.** `FetchNodeImage` (`app/Actions/Node/Build/FetchNodeImage.php`)
  runs in `DeployNodeWorkloadJob` before the deploy request. It returns
  without a fetch when the image is not local, when the target built it, or
  when the target runs or keeps it.
- **`image.serve.v1`.** Input: the transfer ID, the image, the WireGuard
  address of the target, the secret, and the expiry. The source runs
  `podman save --format oci-dir --uncompressed` into
  `/var/lib/coolify/image-transfer/<id>`, starts the server on a port from
  7800-7899, and adds the nftables element. The response has the address, the
  port, the repository, the manifest digest, and the image ID. The serve stops
  on a request with `stop`, at the expiry, or when Sentinel restarts.
- **`image.fetch.v1`.** It is asynchronous. The first request starts
  `podman pull` from the source, and the next requests with the same transfer
  ID return the state (`running`, `succeeded`, or `failed`). The target checks
  the image ID before it tags the local reference. When the image is already
  present, the fetch succeeds immediately.
- **Timeouts.** The Flux request of `image.serve.v1` waits up to about 10
  minutes for the export. Coolify polls `image.fetch.v1` every 2 seconds and
  waits up to 10 minutes for the copy in a deploy. Sentinel lets a pull run up
  to 2 hours. A serve expires after 30 minutes when nothing stops it.

## Consequences

- A cluster with two or more Nodes needs no registry. A move or a placement on
  a new Node needs no rebuild and no push.
- Coolify runs no registry of its own. A user who wants a copy of each image
  outside the Nodes uses `EXTERNAL`.
- An image is only on the Nodes that run it or built it. No Node keeps a copy
  of images that it does not use.
- An image that is only on an offline Node is not available until the Node
  returns. A Git application can be built again; other recovery needs a
  registry.
- The source needs free disk for one uncompressed export of the image while a
  transfer runs.
- Each transfer reads the full image on the source. The network sends only the
  missing layers.
- A peer copy has a different manifest digest than a registry copy. Coolify
  refers to local images by reference and image ID, not by manifest digest.

## Not decided here

- A second copy of each local image on another Node at build time, so that the
  image survives the loss of one Node.
- Fetch of registry images from a peer when the registry is offline.
- A Go helper that streams single layers from `containers-storage` in place of
  the full export, if large images make the export too slow.
- Partial pulls of `zstd:chunked` images.
- A push from the computer of a user to a Node without a registry (`uc image
  push` of Uncloud).

## Options compared

| Option | Good | Bad |
| --- | --- | --- |
| **A. Fetch from a peer, controlled by Coolify (decided)** | No registry, no second copy, only missing layers move, one pattern with the volume transfer | Not available when the only Node with the image is offline. One full export on the source for each transfer |
| B. Push to every Node at build time (Uncloud) | The image survives the loss of one Node | Each image on every Node, also Nodes that never run it |
| C. Registry mirror on each Node (Spegel, unregistry pull) | Transparent for Podman | Does not work on Podman: the store has no compressed blobs, so the digests do not match |
| D. zot replicas | A second copy, registry tools | More registry state and Nodes to manage. zot was removed (history) |
| E. One `podman save \| podman load` stream | Simple | Sends the full image each time |

## Sources

- [unregistry](https://pkg.go.dev/github.com/psviderski/unregistry)
- [uc image push](https://uncloud.run/docs/cli-reference/uc_image_push)
- [Uncloud v0.13.0 release notes](https://newreleases.io/project/github/psviderski/uncloud/release/v0.13.0)
- [podman image scp: digests of the compressed blobs are not kept](https://docs.podman.io/en/latest/markdown/podman-image-scp.1.html)
