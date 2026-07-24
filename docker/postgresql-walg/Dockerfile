ARG POSTGRES_MAJOR=18

FROM postgres:${POSTGRES_MAJOR}-bookworm

ARG TARGETARCH
ARG WAL_G_VERSION=v3.0.8
ARG WAL_G_SHA256_AMD64=9a09c2b1afad6a4e7d87444b34726dd098b60ec816032af921d2f887e6e285c5
ARG WAL_G_SHA256_ARM64=c3dc13b90fce8fe498742143f9ae07db19a603f7f26de00f161419e163e6175f

RUN set -eux; \
    apt-get update; \
    apt-get install --yes --no-install-recommends ca-certificates curl; \
    case "${TARGETARCH}" in \
        amd64) wal_g_arch='amd64'; wal_g_sha256="${WAL_G_SHA256_AMD64}" ;; \
        arm64) wal_g_arch='aarch64'; wal_g_sha256="${WAL_G_SHA256_ARM64}" ;; \
        *) echo "Unsupported architecture: ${TARGETARCH}" >&2; exit 1 ;; \
    esac; \
    curl --fail --show-error --location \
        "https://github.com/wal-g/wal-g/releases/download/${WAL_G_VERSION}/wal-g-pg-20.04-${wal_g_arch}" \
        --output /usr/local/bin/wal-g; \
    echo "${wal_g_sha256}  /usr/local/bin/wal-g" | sha256sum --check --strict; \
    chmod 0755 /usr/local/bin/wal-g; \
    wal-g --version; \
    apt-get purge --yes --auto-remove curl; \
    rm -rf /var/lib/apt/lists/*

LABEL org.opencontainers.image.source="https://github.com/coollabsio/coolify" \
      org.opencontainers.image.description="PostgreSQL with WAL-G for Coolify point-in-time recovery" \
      coolify.managed="true"
