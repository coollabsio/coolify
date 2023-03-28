FROM alpine:3.17

ARG TARGETPLATFORM
# https://download.docker.com/linux/static/stable/
ARG DOCKER_VERSION=20.10.18
# https://github.com/docker/compose/releases
# Reverted to 2.6.1 because of this https://github.com/docker/compose/issues/9704. 2.9.0 still has a bug.
ARG DOCKER_COMPOSE_VERSION=2.6.1
# https://github.com/buildpacks/pack/releases
ARG PACK_VERSION=0.27.0
# https://github.com/railwayapp/nixpacks/releases
ARG NIXPACKS_VERSION=1.6.0

USER root
RUN apk add --no-cache bash curl git git-lfs openssh-client tar tini
RUN mkdir -p ~/.docker/cli-plugins
RUN curl -SL https://cdn.coollabs.io/bin/$TARGETPLATFORM/docker-$DOCKER_VERSION -o /usr/bin/docker
RUN curl -SL https://cdn.coollabs.io/bin/$TARGETPLATFORM/docker-compose-linux-$DOCKER_COMPOSE_VERSION -o ~/.docker/cli-plugins/docker-compose
RUN curl -SL https://cdn.coollabs.io/bin/$TARGETPLATFORM/pack-v$PACK_VERSION -o /usr/local/bin/pack
RUN curl -sSL https://nixpacks.com/install.sh | bash
RUN chmod +x ~/.docker/cli-plugins/docker-compose /usr/bin/docker /usr/local/bin/pack

ENTRYPOINT ["/sbin/tini", "--"]
CMD ["sh", "-c", "while true; do sleep 1000; done"]

