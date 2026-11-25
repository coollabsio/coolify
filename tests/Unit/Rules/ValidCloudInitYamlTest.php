<?php

use App\Rules\ValidCloudInitYaml;

it('accepts valid cloud-config YAML with header', function () {
    $rule = new ValidCloudInitYaml;
    $valid = true;

    $script = <<<'YAML'
#cloud-config
users:
  - name: demo
    groups: sudo
    shell: /bin/bash
packages:
  - nginx
  - git
runcmd:
  - echo "Hello World"
YAML;

    $rule->validate('script', $script, function ($message) use (&$valid) {
        $valid = false;
    });

    expect($valid)->toBeTrue();
});

it('accepts valid cloud-config YAML without header', function () {
    $rule = new ValidCloudInitYaml;
    $valid = true;

    $script = <<<'YAML'
users:
  - name: demo
    groups: sudo
packages:
  - nginx
YAML;

    $rule->validate('script', $script, function ($message) use (&$valid) {
        $valid = false;
    });

    expect($valid)->toBeTrue();
});

it('accepts valid bash script with shebang', function () {
    $rule = new ValidCloudInitYaml;
    $valid = true;

    $script = <<<'BASH'
#!/bin/bash
apt update
apt install -y nginx
systemctl start nginx
BASH;

    $rule->validate('script', $script, function ($message) use (&$valid) {
        $valid = false;
    });

    expect($valid)->toBeTrue();
});

it('accepts empty or null script', function () {
    $rule = new ValidCloudInitYaml;
    $valid = true;

    $rule->validate('script', '', function ($message) use (&$valid) {
        $valid = false;
    });

    expect($valid)->toBeTrue();

    $rule->validate('script', null, function ($message) use (&$valid) {
        $valid = false;
    });

    expect($valid)->toBeTrue();
});

it('rejects invalid YAML format', function () {
    $rule = new ValidCloudInitYaml;
    $valid = true;
    $errorMessage = '';

    $script = <<<'YAML'
#cloud-config
users:
  - name: demo
    groups: sudo
  invalid_indentation
packages:
  - nginx
YAML;

    $rule->validate('script', $script, function ($message) use (&$valid, &$errorMessage) {
        $valid = false;
        $errorMessage = $message;
    });

    expect($valid)->toBeFalse();
    expect($errorMessage)->toContain('YAML');
});

it('rejects script that is neither bash nor valid YAML', function () {
    $rule = new ValidCloudInitYaml;
    $valid = true;
    $errorMessage = '';

    $script = <<<'INVALID'
this is not valid YAML
  and has invalid indentation:
    - item
  without proper structure {
INVALID;

    $rule->validate('script', $script, function ($message) use (&$valid, &$errorMessage) {
        $valid = false;
        $errorMessage = $message;
    });

    expect($valid)->toBeFalse();
    expect($errorMessage)->toContain('bash script');
});

it('accepts complex cloud-config with multiple sections', function () {
    $rule = new ValidCloudInitYaml;
    $valid = true;

    $script = <<<'YAML'
#cloud-config
users:
  - name: coolify
    groups: sudo, docker
    shell: /bin/bash
    sudo: ['ALL=(ALL) NOPASSWD:ALL']
    ssh_authorized_keys:
      - ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABAQ...

packages:
  - docker.io
  - docker-compose
  - git
  - curl

package_update: true
package_upgrade: true

runcmd:
  - systemctl enable docker
  - systemctl start docker
  - usermod -aG docker coolify
  - echo "Server setup complete"

write_files:
  - path: /etc/docker/daemon.json
    content: |
      {
        "log-driver": "json-file",
        "log-opts": {
          "max-size": "10m",
          "max-file": "3"
        }
      }
YAML;

    $rule->validate('script', $script, function ($message) use (&$valid) {
        $valid = false;
    });

    expect($valid)->toBeTrue();
});
