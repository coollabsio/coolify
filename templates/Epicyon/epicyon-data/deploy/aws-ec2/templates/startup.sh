#!/usr/bin/env bash
sudo apt update -y
sudo apt install -y tor python3-socks imagemagick python3-setuptools python3-cryptography python3-dateutil python3-idna python3-requests python3-flake8 python3-django-timezone-field python3-pyqrcode python3-png python3-bandit libimage-exiftool-perl certbot nginx wget
cd /opt || exit
sudo git clone --depth 1 https://gitlab.com/bashrc2/epicyon
cd /opt/epicyon || exit
sudo adduser --system --home=/opt/epicyon --group epicyon
sudo mkdir /var/www/$domain
sudo mkdir -p /opt/epicyon/accounts/newsmirror
sudo ln -s /opt/epicyon/accounts/newsmirror /var/www/$domain/newsmirror

sudo tee /tmp/epicyon.service >/dev/null <<EOF
[Unit]
Description=epicyon
After=syslog.target
After=network.target
[Service]
Type=simple
User=epicyon
Group=epicyon
WorkingDirectory=/opt/epicyon
ExecStart=/usr/bin/python3 /opt/epicyon/epicyon.py --port 443 --proxy 7156 --domain $domain --registration open --log_login_failures
Environment=USER=epicyon
Environment=PYTHONUNBUFFERED=true
Environment=PYTHONIOENCODING=utf-8
Restart=always
StandardError=syslog
CPUQuota=80%
ProtectHome=true
ProtectKernelTunables=true
ProtectKernelModules=true
ProtectControlGroups=true
ProtectKernelLogs=true
ProtectHostname=true
ProtectClock=true
ProtectProc=invisible
ProcSubset=pid
PrivateTmp=true
PrivateUsers=true
PrivateDevices=true
PrivateIPC=true
MemoryDenyWriteExecute=true
NoNewPrivileges=true
LockPersonality=true
RestrictRealtime=true
RestrictSUIDSGID=true
RestrictNamespaces=true
SystemCallArchitectures=native
[Install]
WantedBy=multi-user.target
EOF

sudo mv /tmp/epicyon.service /etc/systemd/system/
sudo chown -R epicyon:epicyon /opt/epicyon 
sudo systemctl daemon-reload && sudo systemctl start epicyon &&  sudo systemctl enable epicyon

sudo tee /tmp/$domain >/dev/null <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name $domain;
    access_log /dev/null;
    error_log /dev/null;
    client_max_body_size 31m;
    client_body_buffer_size 128k;
    index index.html;
    rewrite ^ https://\$server_name\$request_uri? permanent;
}
server {
    listen 443 ssl;
    server_name $domain;
    gzip on;
    gzip_disable "msie6";
    gzip_vary on;
    gzip_proxied any;
    gzip_min_length 1024;
    gzip_comp_level 6;
    gzip_buffers 16 8k;
    gzip_http_version 1.1;
    gzip_types text/plain text/css application/json application/ld+json application/javascript text/xml application/xml application/rdf+xml application/xml+rss text/javascript;
    ssl_stapling off;
    ssl_stapling_verify off;
    ssl on;
    ssl_certificate /etc/letsencrypt/live/$domain/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/$domain/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!MEDIUM:!LOW:!aNULL:!NULL:!SHA;
    ssl_prefer_server_ciphers on;
    ssl_session_cache shared:SSL:10m;
    ssl_session_tickets off;
    add_header Content-Security-Policy "default-src https:; script-src https: 'unsafe-inline'; style-src https: 'unsafe-inline'";
    add_header X-Frame-Options DENY;
    add_header X-Content-Type-Options nosniff;
    add_header X-XSS-Protection "1; mode=block";
    add_header X-Download-Options noopen;
    add_header X-Permitted-Cross-Domain-Policies none;
	add_header Strict-Transport-Security "max-age=15768000; includeSubDomains; preload" always;
    access_log /dev/null;
    error_log /dev/null;
    index index.html;
    location /newsmirror {
        root /var/www/$domain;
        try_files \$uri =404;
    }
    keepalive_timeout 70;
    sendfile on;
    location / {
        proxy_http_version 1.1;
        client_max_body_size 31M;
        proxy_set_header Host \$http_host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forward-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forward-Proto http;
        proxy_set_header X-Nginx-Proxy true;
        proxy_temp_file_write_size 64k;
        proxy_connect_timeout 10080s;
        proxy_send_timeout 10080;
        proxy_read_timeout 10080;
        proxy_buffer_size 64k;
        proxy_buffers 16 32k;
        proxy_busy_buffers_size 64k;
        proxy_redirect off;
        proxy_request_buffering off;
        proxy_buffering off;
        proxy_pass http://localhost:7156;
        tcp_nodelay on;
    }
}
EOF

sudo mv /tmp/$domain /etc/nginx/sites-available/
sudo ln -s /etc/nginx/sites-available/$domain /etc/nginx/sites-enabled/
sudo systemctl stop nginx
sudo certbot certonly -n --server https://acme-v02.api.letsencrypt.org/directory --standalone -d $domain --renew-by-default --agree-tos --email $email
sudo systemctl enable nginx
sudo systemctl start nginx
