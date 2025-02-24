# Versions
# https://download.docker.com/linux/static/stable/
ARG DOCKER_VERSION=28.0.0
# https://github.com/docker/compose/releases
ARG DOCKER_COMPOSE_VERSION=2.33.1
# https://github.com/docker/buildx/releases
ARG DOCKER_BUILDX_VERSION=0.21.1


FROM debian:12-slim

ARG TARGETPLATFORM
ARG DOCKER_VERSION
ARG DOCKER_COMPOSE_VERSION
ARG DOCKER_BUILDX_VERSION

USER root
WORKDIR /root
ENV PATH="/host/usr/local/sbin:/host/usr/local/bin:/host/usr/sbin:/host/usr/bin:/host/sbin:/host/bin:$PATH"

RUN apt update && apt -y install openssh-client openssh-server curl wget git jq jc
RUN mkdir -p ~/.docker/cli-plugins
RUN curl -sSL https://github.com/docker/buildx/releases/download/v${DOCKER_BUILDX_VERSION}/buildx-v${DOCKER_BUILDX_VERSION}.linux-amd64 -o ~/.docker/cli-plugins/docker-buildx
RUN curl -sSL https://github.com/docker/compose/releases/download/v${DOCKER_COMPOSE_VERSION}/docker-compose-linux-x86_64 -o ~/.docker/cli-plugins/docker-compose
RUN (curl -sSL https://download.docker.com/linux/static/stable/x86_64/docker-${DOCKER_VERSION}.tgz | tar -C /usr/bin/ --no-same-owner -xzv --strip-components=1 docker/docker)
RUN chmod +x ~/.docker/cli-plugins/docker-compose /usr/bin/docker /root/.docker/cli-plugins/docker-buildx


# Setup sshd
RUN ssh-keygen -A
RUN mkdir -p /run/sshd
RUN mkdir -p ~/.ssh
RUN echo "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFuGmoeGq/pojrsyP1pszcNVuZx9iFkCELtxrh31QJ68 coolify@coolify-instance" >> ~/.ssh/authorized_keys

EXPOSE 22
CMD ["/usr/sbin/sshd", "-D", "-o", "ListenAddress=0.0.0.0", "-o", "Port=22"]
