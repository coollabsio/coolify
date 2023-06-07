#!/bin/bash
curl https://releases.rancher.com/install-docker/23.0.sh | sh
echo "Docker installed successfully"
echo '{ "live-restore": true }' >/etc/docker/daemon.json
systemctl restart docker
