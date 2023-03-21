FROM ubuntu:22.04

ARG TARGETPLATFORM

# https://download.docker.com/linux/static/stable/
ARG DOCKER_VERSION=20.10.18
# https://github.com/docker/compose/releases
# Reverted to 2.6.1 because of this https://github.com/docker/compose/issues/9704. 2.9.0 still has a bug.
ARG DOCKER_COMPOSE_VERSION=2.6.1
# https://github.com/buildpacks/pack/releases
ARG PACK_VERSION=v0.27.0

ENV DEBIAN_FRONTEND noninteractive
ENV TZ=UTC

RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

RUN apt-get update \
    && apt-get install -y gnupg gosu curl ca-certificates zip unzip git git-lfs supervisor  \
        sqlite3 libcap2-bin libpng-dev python2 dnsutils openssh-server sudo \
    && apt-get -y autoremove \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# Setup sshd
RUN ssh-keygen -A
RUN mkdir -p /run/sshd

# Install Docker CLI, Docker Compose, and Pack
RUN mkdir -p ~/.docker/cli-plugins
RUN curl -SL https://cdn.coollabs.io/bin/$TARGETPLATFORM/docker-$DOCKER_VERSION -o /usr/bin/docker
RUN curl -SL https://cdn.coollabs.io/bin/$TARGETPLATFORM/docker-compose-linux-$DOCKER_COMPOSE_VERSION -o ~/.docker/cli-plugins/docker-compose
RUN curl -SL https://cdn.coollabs.io/bin/$TARGETPLATFORM/pack-$PACK_VERSION -o /usr/local/bin/pack
RUN curl -sSL https://nixpacks.com/install.sh | bash
RUN chmod +x ~/.docker/cli-plugins/docker-compose /usr/bin/docker /usr/local/bin/pack
RUN groupadd docker

# Setup coolify user
RUN useradd -ms /bin/bash coolify
RUN usermod -aG sudo coolify
RUN usermod -aG docker coolify
RUN echo 'coolify ALL=(ALL) NOPASSWD:ALL' >> /etc/sudoers

# Setup ssh'ing into the destination as Coolify User
USER coolify
RUN mkdir -p ~/.ssh
RUN echo "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFuGmoeGq/pojrsyP1pszcNVuZx9iFkCELtxrh31QJ68 coolify@coolify-instance" >> ~/.ssh/authorized_keys

USER root

# Setup ssh'ing into the destination as Root
RUN mkdir -p ~/.ssh
RUN echo "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFuGmoeGq/pojrsyP1pszcNVuZx9iFkCELtxrh31QJ68 coolify@coolify-instance" >> ~/.ssh/authorized_keys

EXPOSE 22

COPY start-container /usr/local/bin/start-container
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf
RUN chmod +x /usr/local/bin/start-container

WORKDIR /root

# Prepare projects
RUN mkdir -p projects
COPY dummy-project projects/dummy-project

ENTRYPOINT ["start-container"]
