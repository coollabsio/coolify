# Versions
# https://hub.docker.com/_/alpine
ARG BASE_IMAGE=alpine:3.24@sha256:be2656061b536c1883c4034790e21d0a09c5aaaf51968b89368a3c0371ea2c9a
# https://quay.io/repository/minio/aistor/mc?tab=tags
ARG MINIO_CLIENT_IMAGE=quay.io/minio/aistor/mc:RELEASE.2026-09-19T15-24-59Z@sha256:23511e340cbabf07e6a8b53f9c434975708f22f3c047d6beeddc17bf1a5aed88
# https://download.docker.com/linux/static/stable/
ARG DOCKER_VERSION=29.9.2
# https://github.com/docker/compose/releases
ARG DOCKER_COMPOSE_VERSION=5.6.1
# https://github.com/docker/buildx/releases
ARG DOCKER_BUILDX_VERSION=0.38.1
# https://github.com/buildpacks/pack/releases
ARG PACK_VERSION=0.40.10
# https://github.com/railwayapp/nixpacks/releases
ARG NIXPACKS_VERSION=1.41.0
# https://github.com/railwayapp/railpack/releases
ARG RAILPACK_VERSION=0.43.0
# https://github.com/jdx/mise/releases — must match the version pinned at that railpack tag (https://raw.githubusercontent.com/railwayapp/railpack/v${RAILPACK_VERSION}/core/mise/version.txt)
ARG MISE_VERSION=2026.10.14

FROM ${MINIO_CLIENT_IMAGE} AS minio-client

FROM ${BASE_IMAGE} AS base

ARG TARGETARCH
ARG DOCKER_VERSION
ARG DOCKER_COMPOSE_VERSION
ARG DOCKER_BUILDX_VERSION
ARG PACK_VERSION
ARG NIXPACKS_VERSION
ARG RAILPACK_VERSION
ARG MISE_VERSION

USER root
WORKDIR /artifacts
ENV RAILPACK_VERSION=${RAILPACK_VERSION}
RUN apk upgrade --no-cache && \
    apk add --no-cache bash curl git git-lfs openssh-client pigz tar tini
SHELL ["/bin/bash", "-euo", "pipefail", "-c"]

# SHA256 of every download, per architecture. After you change a version above, run scripts/helper-checksums to update them.
COPY <<'EOF' /tmp/SHA256SUMS
9ac225999fd5e863f7694c728ef23d480616b1e5639328fa309befd4243d69eb  docker-amd64.tgz
873b3e0207857c3b2f28392fb922f3ea6efe37620c16d4d4aaf2fef409c90b73  docker-arm64.tgz
e25d5cd675092bb5d1d03632fdc46cdf3fede7b514dc5f023a105e00d3ce5ad4  docker-buildx-amd64
3b83b3731916ba433feccaaa689fe5bcdc81c641d92c8e6c2ea865dfa141c97a  docker-buildx-arm64
47737f4236e6d3ccea7291995203f8f018058783b4c3a9469119811a0b5db8c0  docker-compose-amd64
42cfa75f51ad0ccf290bad37899e0038a8c10a0069e85233ecc1d53e3ff1347d  docker-compose-arm64
b0e388c8014f9ac14a5ddf7a4042b0db28043f7f9ce485ffc2521bff02247f8e  mise-amd64
c71eec4d0015f6ced74ac9d5cd755b56674df04e899d0d1566d533c95511ae6c  mise-arm64
0f55de7874507b9cf7502113120bd96f2ab6979f78d10eaf2eb2ade9207b3af6  nixpacks-amd64.tgz
912bd02dd2bb6f9c3a9ed965fe8a68b4aa318dc7a2546e2eca6f2806a894ba39  nixpacks-arm64.tgz
687c3616e21824a449b56d4f989613ca68c921c6cce670b4de4459b025233806  pack-amd64.tgz
9a294abb0576fd95e19a009b3f9d5f5aa9fecdc2d7da7fa05e7e1e94f5085d77  pack-arm64.tgz
248a8f60f36854aabed6124113631078a802de3461660855fc4de9c26065c1fb  railpack-amd64.tgz
732ece14eb4f4676a43ebba19431f954edf7b130acb76159ef0f2ff86ac42ac9  railpack-arm64.tgz
EOF

RUN case "${TARGETARCH}" in \
        amd64) DOCKER_ARCH=x86_64; PACK_SUFFIX=""; NIXPACKS_ARCH=x86_64; RAILPACK_ARCH=x86_64; MISE_ARCH=x64 ;; \
        arm64) DOCKER_ARCH=aarch64; PACK_SUFFIX="-arm64"; NIXPACKS_ARCH=aarch64; RAILPACK_ARCH=arm64; MISE_ARCH=arm64 ;; \
        *) echo "Unsupported architecture: ${TARGETARCH}" >&2; exit 1 ;; \
    esac && \
    mkdir -p /tmp/downloads && cd /tmp/downloads && \
    download() { curl -fsSL --proto '=https' --tlsv1.2 --retry 3 -o "$1" "$2"; } && \
    download "docker-${TARGETARCH}.tgz" "https://download.docker.com/linux/static/stable/${DOCKER_ARCH}/docker-${DOCKER_VERSION}.tgz" && \
    download "docker-compose-${TARGETARCH}" "https://github.com/docker/compose/releases/download/v${DOCKER_COMPOSE_VERSION}/docker-compose-linux-${DOCKER_ARCH}" && \
    download "docker-buildx-${TARGETARCH}" "https://github.com/docker/buildx/releases/download/v${DOCKER_BUILDX_VERSION}/buildx-v${DOCKER_BUILDX_VERSION}.linux-${TARGETARCH}" && \
    download "pack-${TARGETARCH}.tgz" "https://github.com/buildpacks/pack/releases/download/v${PACK_VERSION}/pack-v${PACK_VERSION}-linux${PACK_SUFFIX}.tgz" && \
    download "nixpacks-${TARGETARCH}.tgz" "https://github.com/railwayapp/nixpacks/releases/download/v${NIXPACKS_VERSION}/nixpacks-v${NIXPACKS_VERSION}-${NIXPACKS_ARCH}-unknown-linux-musl.tar.gz" && \
    download "railpack-${TARGETARCH}.tgz" "https://github.com/railwayapp/railpack/releases/download/v${RAILPACK_VERSION}/railpack-v${RAILPACK_VERSION}-${RAILPACK_ARCH}-unknown-linux-musl.tar.gz" && \
    download "mise-${TARGETARCH}" "https://github.com/jdx/mise/releases/download/v${MISE_VERSION}/mise-v${MISE_VERSION}-linux-${MISE_ARCH}-musl" && \
    grep -- "-${TARGETARCH}" /tmp/SHA256SUMS | sha256sum -c - && \
    mkdir -p /root/.docker/cli-plugins /tmp/railpack/mise && \
    tar -xzf "docker-${TARGETARCH}.tgz" -C /usr/bin --no-same-owner --strip-components=1 docker/docker && \
    install -m 0755 "docker-compose-${TARGETARCH}" /root/.docker/cli-plugins/docker-compose && \
    install -m 0755 "docker-buildx-${TARGETARCH}" /root/.docker/cli-plugins/docker-buildx && \
    tar -xzf "pack-${TARGETARCH}.tgz" -C /usr/local/bin --no-same-owner pack && \
    tar -xzf "nixpacks-${TARGETARCH}.tgz" -C /usr/local/bin --no-same-owner nixpacks && \
    tar -xzf "railpack-${TARGETARCH}.tgz" -C /usr/local/bin --no-same-owner railpack && \
    # Railpack skips its own mise download when this file exists.
    install -m 0755 "mise-${TARGETARCH}" "/tmp/railpack/mise/mise-${MISE_VERSION}" && \
    cd / && rm -rf /tmp/downloads /tmp/SHA256SUMS && \
    docker --version && docker compose version && docker buildx version && pack --version && \
    nixpacks --version && railpack --version

COPY --from=minio-client /usr/bin/mc /usr/bin/mc
RUN chmod +x /usr/bin/mc && mc --version

ENTRYPOINT ["/sbin/tini", "--"]
CMD ["tail", "-f", "/dev/null"]
