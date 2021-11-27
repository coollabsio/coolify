config_version = 2

name = "easy_gar"

mode = "single"

status = ""

dataplaneapi {
  host = "0.0.0.0"
  port = 5555

  user "haproxy-dataplaneapi" {
    insecure = true
    password = "adminpwd"
  }

  transaction {
    transaction_dir = "/tmp/haproxy"
  }

  advertised {
    api_address = ""
    api_port    = 0
  }
}

haproxy {
  config_file = "/usr/local/etc/haproxy/haproxy.cfg"
  haproxy_bin = "/usr/local/sbin/haproxy"

  reload {
    reload_delay = 5
    reload_cmd   = "kill -SIGUSR2 1"
    restart_cmd  = "kill -SIGUSR2 1"
  }
}
