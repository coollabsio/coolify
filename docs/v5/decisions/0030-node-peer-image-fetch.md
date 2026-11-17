# Decision 0030: Copy local images between Nodes without a registry

**Status: Proposed**

**Date: 2026-11-17**

## Context

A cluster stores its builds in one of three image storages (decision 0006):

- `EXTERNAL`: a registry of the team, for example GHCR or Docker Hub;
- `BUILT_IN`: zot on one Node of the cluster;
- `LOCAL`: the Podman store of the only Node (`image.load.v1`), as
  `localhost/<workload>:<build>`.

A local image is only on the Node that built or loaded it. A move to another
Node (`CreateMoveOperation`, `MoveNodeWorkloadJob`) needs the image on the
target. Today the move pushes the local image to the image storage of the
cluster first (`image.push.v1`, `PushLocalImage`). When the second Node joins,
the user must choose a registry, and builds fail until the user chooses.

The built-in registry keeps a second copy of each image, it is one point for
all pulls, and it loses its images when it moves to another Node (decision
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
  more registry state, not less.
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

- `image.serve.v1` on the source. Input: the image ID, the target Node address,
  a transfer ID, and a token. It exports the image, starts the server on a
  port from the transfer range 7800-7899 (shared with the volume transfer),
  adds the element `<target_ip> . <port>` to the transfer set of the
  `coolify_cluster` table, and returns the port and the manifest digest. It
  removes the export, the server, and the element when the fetch is done, or
  after a timeout.
- `image.fetch.v1` on the target. Input: the source address, the port, the
  digest, the token, the transfer ID, and the original reference. It opens the
  output element to the source while it pulls, then tags and checks the image.
  It returns the image ID and the bytes that moved.

Both keep their state in memory only. The token is never journaled. On start,
Sentinel removes the exports, servers, and firewall elements that an earlier
process left behind.

### Where Coolify uses it

- **Move.** A move of a revision with a local image fetches it from the source
  Node when both Nodes report the capabilities. This replaces the push to the
  image storage (`PushLocalImage`) in the move. When the source is offline, the
  move stays blocked with the current message ("The image is only on
  <node>, which is offline. Deploy the application again, then recover it.").
- **Deploy, rollback, and placement.** When Coolify places a revision with a
  local image on a Node that does not have it, it fetches the image first. The
  sources are, in this order: the Node that built the revision, then the other
  Nodes that ran it. Coolify tries the next source when `image.serve.v1` fails
  or the source is offline. When no source has the image, a Git application is
  built again (decision 0017).
- **Order of sources for a Node:** the local store, then a peer (local images
  only), then the registry of the revision, then a new build or a clear error.

### Image storage of a cluster

- `LOCAL` stays a valid storage when the cluster has two or more Nodes and
  every Node reports `image.serve.v1` and `image.fetch.v1`. Each build loads its
  image into the Podman store of its build Node (`image.load.v1`), and other
  Nodes fetch it from there. `NodeCluster::imageStorage()` no longer derives
  `LOCAL` only from one Node; the cluster stores the choice.
- When the second Node joins, `LOCAL` is the default when every Node has the
  capabilities. The user can still select `EXTERNAL` or `BUILT_IN`. Builds do
  not fail while no choice is made.
- **External registries stay.** A cluster with `EXTERNAL` pushes each build to
  the registry of the team, as today. Images from a registry (an `EXTERNAL`
  build, a `BUILT_IN` build, or an image source such as `nginx:1.27`) always
  come from their registry by digest (decision 0017). Coolify does not fetch
  them from a peer, because the peer copy has a different manifest digest and
  the revision refers to the registry digest.
- `BUILT_IN` stays for users who want one copy of each image outside the Nodes
  that run it, or many replicas.

### Security

- All Nodes of a cluster belong to one team, and every Node already has the
  pull credential of the cluster registry. A fetch between Nodes of one cluster
  crosses no new boundary.
- The server listens only on the WireGuard address, and only the target of the
  transfer can reach it (nftables element). It never listens on a public
  address.
- Each transfer has its own token from Coolify. The server refuses requests
  without it.
- The server serves only the image of the transfer. It never serves other
  images of the store, the build cache, or the zot data.
- Coolify accepts a source and a target only when both are active Nodes of the
  cluster of the revision.

### Cleanup

- The image cleanup (decision 0027) keeps the images of the deployed revision
  and of the last 2 earlier revisions of each workload on each Node. A rollback
  or a move can therefore fetch these images from the Nodes that ran them.
- The build Node keeps the images of its recent builds by the same keep list,
  also when no workload runs on it.
- The export directory of a transfer is temporary and is removed when the
  transfer ends.

### Capabilities

Coolify fetches from a peer only when the source reports `image.serve.v1` and
the target reports `image.fetch.v1`. Without them, a move uses the push to the
image storage (`image.push.v1`), as today.

## Consequences

- A cluster with two or more Nodes needs no registry. A move or a placement on
  a new Node needs no rebuild and no push.
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
- Removal of `image.push.v1` when all Sentinels report the fetch capabilities.

## Options compared

| Option | Good | Bad |
| --- | --- | --- |
| **A. Fetch from a peer, controlled by Coolify (decided)** | No registry, no second copy, only missing layers move, one pattern with the volume transfer | Not available when the only Node with the image is offline. One full export on the source for each transfer |
| B. Push to every Node at build time (Uncloud) | The image survives the loss of one Node | Each image on every Node, also Nodes that never run it |
| C. Registry mirror on each Node (Spegel, unregistry pull) | Transparent for Podman | Does not work on Podman: the store has no compressed blobs, so the digests do not match |
| D. zot replicas | A second copy, registry tools | More registry state and Nodes to manage |
| E. One `podman save \| podman load` stream | Simple | Sends the full image each time |

## Sources

- [unregistry](https://pkg.go.dev/github.com/psviderski/unregistry)
- [uc image push](https://uncloud.run/docs/cli-reference/uc_image_push)
- [Uncloud v0.13.0 release notes](https://newreleases.io/project/github/psviderski/uncloud/release/v0.13.0)
- [podman image scp: digests of the compressed blobs are not kept](https://docs.podman.io/en/latest/markdown/podman-image-scp.1.html)
