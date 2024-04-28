#!/bin/bash

# Once ypu have connected your  coolify instance to a domain name and your server  provider does  not  provide a firewall 
# if you try   to  seup *ufw* it wont work so try the command below to change ip tables and disable access on port  800 where coolify runs

iptables -I PREROUTING 1 -t mangle  -p tcp --dport 8000 -j DROP

#  to restore  the  port access copy the command below and run  it

# iptables -I PREROUTING 1 -t mangle  -p tcp --dport 8000 -j ACCEPT
