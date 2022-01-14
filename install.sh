WHO=$(whoami)
if [ $WHO != 'root' ]; then
    echo 'Run as root please: sudo sh -c "$(curl -fsSL https://get.coollabs.io/coolify/install.sh)"'
    exit
fi
# Install docker
sh -c "$(curl -fsSL https://get.docker.com)"

# Set /etc/docker/daemon.json
cat <<EOF > /etc/docker/daemon.json
{
    "log-driver": "json-file",
    "log-opts": {
      "max-size": "100m",
      "max-file": "5"
    },
    "features": {
        "buildkit": true
    },
    "live-restore": true
}
EOF
sh -c "systemctl daemon-reload && systemctl restart docker"

mkdir -p ~/.docker/cli-plugins/
curl -SL https://github.com/docker/compose/releases/download/v2.2.2/docker-compose-linux-x86_64 -o ~/.docker/cli-plugins/docker-compose
chmod +x ~/.docker/cli-plugins/docker-compose


mkdir coolify && cd coolify

echo -e 'COOLIFY_APP_ID=test\nCOOLIFY_SECRET_KEY=12341234123412341234123412341235\nCOOLIFY_DATABASE_URL=file:../
db/prod.db\nCOOLIFY_SENTRY_DSN=https://' > .env


docker volume create coolify-db-sqlite 
docker volume create coolify-ssl-certs 
docker volume create coolify-letsencrypt

docker run -tid --env-file .env -v /var/run/docker.sock:/var/run/docker.sock -v coolify-db-sqlite coollabsio/coolify:latest /bin/sh -c "env | grep COOLIFY > .env && docker compose up -d --force-recreate"

echo 'Congratulations! Your coolify is ready to use. Please visit http://<Your Public IP Address>:3000/ to get started.'
