# Email Server Template

A complete email server solution using Docker Mailserver with Roundcube webmail interface.

## Features

- **Full Email Server**: SMTP, IMAP, POP3 support
- **Security**: Anti-spam (Rspamd), Anti-virus (ClamAV), Fail2Ban protection
- **Webmail**: Roundcube web interface for email management
- **SSL/TLS**: Automatic certificate management with Let's Encrypt
- **Multiple Domains**: Support for multiple email domains
- **ManageSieve**: Server-side email filtering

## Quick Start

1. **Set Environment Variables**:
   - `DOMAIN`: Your primary email domain (e.g., `example.com`)

2. **DNS Configuration**:
   Configure the following DNS records for your domain:
   
   MX     @           10 mail.yourdomain.com
   A      mail        <your-server-ip>
   TXT    @           "v=spf1 mx ~all"
   TXT    _dmarc      "v=DMARC1; p=quarantine; rua=mailto:dmarc@yourdomain.com"
   

3. **Deploy**: Use this template in Coolify to deploy your email server

## Post-Deployment Setup

### Creating Email Accounts

Access the mailserver container and create email accounts:


# List all containers
docker ps

# Access the mailserver container
docker exec -it <mailserver_container_name> bash

# Add a new email account
setup email add user@yourdomain.com [password]

# List all accounts
setup email list

# Set up DKIM
setup config dkim


### Accessing Webmail

Roundcube webmail will be available at your configured domain on port 80. Users can log in with their email credentials.

### Managing Aliases


# Add an alias
setup alias add alias@yourdomain.com user@yourdomain.com

# List aliases
setup alias list


## Important Security Notes

1. **Firewall**: Ensure your server firewall allows the necessary ports
2. **Reverse DNS**: Configure reverse DNS (PTR record) for your server IP
3. **Regular Updates**: Keep the Docker images updated for security patches
4. **Backup**: Regularly backup the persistent volumes, especially mail-data

## Troubleshooting

### Check Service Status

# Check if services are running
docker exec <mailserver_container_name> supervisorctl status

# View logs
docker logs <mailserver_container_name>


### Test Email Delivery

# Test SMTP
telnet mail.yourdomain.com 25

# Test IMAP
telnet mail.yourdomain.com 143


### Common Issues

- **Port 25 blocked**: Many cloud providers block port 25. Contact your provider to unblock it.
- **DNS propagation**: Allow time for DNS changes to propagate (up to 48 hours)
- **SSL certificates**: Ensure your domain points to the server before deploying

## Volumes

- `mail-data`: Stores all email messages
- `mail-state`: Contains server state and configuration
- `mail-logs`: Email server logs
- `config`: Docker Mailserver configuration files
- `roundcube-data`: Roundcube webmail data

## Ports

- `25`: SMTP (incoming mail)
- `143`: IMAP (unencrypted)
- `465`: SMTPS (SMTP over SSL)
- `587`: SMTP Submission (for clients)
- `993`: IMAPS (IMAP over SSL)
- `80`: Roundcube webmail interface

For production use, configure a reverse proxy to handle SSL termination for the webmail interface.