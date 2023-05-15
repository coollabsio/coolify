FROM alpine:3.17

ARG TARGETPLATFORM
# https://download.docker.com/linux/static/stable/
ARG DOCKER_VERSION=23.0.5
# https://github.com/docker/compose/releases
ARG DOCKER_COMPOSE_VERSION=2.17.3
# https://github.com/docker/buildx/releases
ARG DOCKER_BUILDX_VERSION=0.10.4
# https://github.com/buildpacks/pack/releases
ARG PACK_VERSION=0.29.0
# https://github.com/railwayapp/nixpacks/releases
ARG NIXPACKS_VERSION=1.6.1

USER root
WORKDIR /root
RUN apk add --no-cache bash curl git git-lfs openssh-client openssh-server tar tini
RUN mkdir -p ~/.docker/cli-plugins
RUN if [[ ${TARGETPLATFORM} == 'linux/amd64' ]]; then \
    curl -sSL https://github.com/docker/buildx/releases/download/v${DOCKER_BUILDX_VERSION}/buildx-v${DOCKER_BUILDX_VERSION}.linux-amd64 -o ~/.docker/cli-plugins/docker-buildx && \
    curl -sSL https://github.com/docker/compose/releases/download/v${DOCKER_COMPOSE_VERSION}/docker-compose-linux-x86_64 -o ~/.docker/cli-plugins/docker-compose && \
    (curl -sSL https://download.docker.com/linux/static/stable/x86_64/docker-${DOCKER_VERSION}.tgz | tar -C /usr/bin/ --no-same-owner -xzv --strip-components=1 docker/docker) && \
    (curl -sSL https://github.com/buildpacks/pack/releases/download/v${PACK_VERSION}/pack-v${PACK_VERSION}-linux.tgz | tar -C /usr/local/bin/ --no-same-owner -xzv pack) && \
    curl -sSL https://nixpacks.com/install.sh | bash && \
    chmod +x ~/.docker/cli-plugins/docker-compose /usr/bin/docker /usr/local/bin/pack /root/.docker/cli-plugins/docker-buildx \
    ;fi

# Setup sshd
RUN ssh-keygen -A
RUN mkdir -p /run/sshd
RUN mkdir -p ~/.ssh
RUN echo "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFuGmoeGq/pojrsyP1pszcNVuZx9iFkCELtxrh31QJ68 coolify@coolify-instance" >> ~/.ssh/authorized_keys

EXPOSE 22
ENTRYPOINT ["/sbin/tini", "--"]
CMD ["/usr/sbin/sshd", "-D", "-o", "ListenAddress=0.0.0.0"]

