const fs = require("fs").promises;
module.exports = async function (workdir) {
  try {
    // TODO: Do it better.
    await fs.writeFile(`${workdir}/.dockerignore`, "node_modules");
    await fs.writeFile(
      `${workdir}/nginx.conf`,
      `user  nginx;
        worker_processes  auto;
        
        error_log  /var/log/nginx/error.log warn;
        pid        /var/run/nginx.pid;
        
        
        events {
            worker_connections  1024;
        }
        
        http {
            include       /etc/nginx/mime.types;
        
            access_log      off;
            sendfile        on;
            #tcp_nopush     on;
            keepalive_timeout  65;
        
            #gzip on;
        
            #include /etc/nginx/conf.d/*.conf;
            server {
                listen       80;
                server_name  localhost;
        
                if ($request_uri ~ (.*?\/)(\/+)$ ) {
                    return 301 $scheme://$host$1;
                }
        
                location / {
                    root   /usr/share/nginx/html;
                    index  index.html;
                    try_files $uri $uri/index.html $uri/ /index.html =404;
                }
        
                #error_page  404              /404.html;
        
                # redirect server error pages to the static page /50x.html
                #
                error_page   500 502 503 504  /50x.html;
                location = /50x.html {
                    root   /usr/share/nginx/html;
                }  
        
            }
        
        }
        `
    );
  } catch (error) {
    throw new Error(error);
  }
};
