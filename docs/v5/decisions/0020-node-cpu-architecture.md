# Decision 0020: Build and place workloads by CPU architecture

**Status: Accepted**

**Date: 2026-11-11**

## Context

A cluster can have Nodes with different CPU architectures (`uname -m`, recorded
when Coolify inspects a Node). Decision 0006 leaves builds for a different CPU
architecture open. Emulation with QEMU is slow.

## Decision

- Coolify builds an image on a builder Node with the target CPU architecture. It
  does not use emulation.
- Coolify places a workload only on Nodes with the architecture of its image.

## Consequences

- A cluster with Nodes of two architectures needs a builder Node of each.

## Not decided here

- Multi-architecture images.
