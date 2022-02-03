config_version = 2
name = "easy_gar"
mode = "single"
status = "null"

dataplaneapi {
  host = "0.0.0.0"
  port = 5555

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
    reload_delay = 2
    reload_cmd   = "kill -HUP 1"
    restart_cmd  = "kill -SIGUSR2 1"
  }
}
