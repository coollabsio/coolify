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

            server {
                listen       80;
                server_name  localhost;
                
                location / {
                    root   /usr/share/nginx/html;
                    index  index.html;
                    try_files $uri $uri/index.html $uri/ /index.html =404;
                }
        
                error_page  404              /50x.html;
        
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
