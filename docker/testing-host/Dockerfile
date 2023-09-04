
FROM debian:12-slim

USER root
WORKDIR /root
ENV PATH "$PATH:/host/usr/local/sbin:/host/usr/local/bin:/host/usr/sbin:/host/usr/bin:/host/sbin:/host/bin"

RUN apt update && apt -y install openssh-client openssh-server curl wget git jq jc

# Setup sshd
RUN ssh-keygen -A
RUN mkdir -p /run/sshd
RUN mkdir -p ~/.ssh
RUN echo "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFuGmoeGq/pojrsyP1pszcNVuZx9iFkCELtxrh31QJ68 coolify@coolify-instance" >> ~/.ssh/authorized_keys

EXPOSE 22
CMD ["/usr/sbin/sshd", "-D", "-o", "ListenAddress=0.0.0.0"]
