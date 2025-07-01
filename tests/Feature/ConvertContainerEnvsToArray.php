<?php

test('convertContainerEnvsToArray', function () {
    $data = '[
      {
          "Id": "c9248632fb1f1ba4b0d885f78ebadf6af6233799a645d2f5c749088dbf55d79f",
          "Created": "2025-05-21T11:58:44.902108064Z",
          "Path": "docker-entrypoint.sh",
          "Args": [
              "postgres"
          ],
          "State": {
              "Status": "running",
              "Running": true,
              "Paused": false,
              "Restarting": false,
              "OOMKilled": false,
              "Dead": false,
              "Pid": 1114005,
              "ExitCode": 0,
              "Error": "",
              "StartedAt": "2025-05-22T08:47:41.404232362Z",
              "FinishedAt": "2025-05-22T08:47:36.222181133Z"
          },
          "Image": "sha256:4100a24644378a24cdbe3def6fc2346999c53d87b12180c221ebb17f05259948",
          "ResolvConfPath": "/var/lib/docker/containers/c9248632fb1f1ba4b0d885f78ebadf6af6233799a645d2f5c749088dbf55d79f/resolv.conf",
          "HostnamePath": "/var/lib/docker/containers/c9248632fb1f1ba4b0d885f78ebadf6af6233799a645d2f5c749088dbf55d79f/hostname",
          "HostsPath": "/var/lib/docker/containers/c9248632fb1f1ba4b0d885f78ebadf6af6233799a645d2f5c749088dbf55d79f/hosts",
          "LogPath": "/var/lib/docker/containers/c9248632fb1f1ba4b0d885f78ebadf6af6233799a645d2f5c749088dbf55d79f/c9248632fb1f1ba4b0d885f78ebadf6af6233799a645d2f5c749088dbf55d79f-json.log",
          "Name": "/coolify-db",
          "RestartCount": 0,
          "Driver": "overlay2",
          "Platform": "linux",
          "MountLabel": "",
          "ProcessLabel": "",
          "AppArmorProfile": "",
          "ExecIDs": null,
          "HostConfig": {
              "Binds": null,
              "ContainerIDFile": "",
              "LogConfig": {
                  "Type": "json-file",
                  "Config": {
                      "max-file": "5",
                      "max-size": "20m"
                  }
              },
              "NetworkMode": "coolify",
              "PortBindings": {
                  "5432/tcp": [
                      {
                          "HostIp": "",
                          "HostPort": "5432"
                      }
                  ]
              },
              "RestartPolicy": {
                  "Name": "always",
                  "MaximumRetryCount": 0
              },
              "AutoRemove": false,
              "VolumeDriver": "",
              "VolumesFrom": null,
              "ConsoleSize": [
                  0,
                  0
              ],
              "CapAdd": null,
              "CapDrop": null,
              "CgroupnsMode": "private",
              "Dns": null,
              "DnsOptions": null,
              "DnsSearch": null,
              "ExtraHosts": [],
              "GroupAdd": null,
              "IpcMode": "private",
              "Cgroup": "",
              "Links": null,
              "OomScoreAdj": 0,
              "PidMode": "",
              "Privileged": false,
              "PublishAllPorts": false,
              "ReadonlyRootfs": false,
              "SecurityOpt": null,
              "UTSMode": "",
              "UsernsMode": "",
              "ShmSize": 8405385216,
              "Runtime": "runc",
              "Isolation": "",
              "CpuShares": 0,
              "Memory": 0,
              "NanoCpus": 0,
              "CgroupParent": "",
              "BlkioWeight": 0,
              "BlkioWeightDevice": null,
              "BlkioDeviceReadBps": null,
              "BlkioDeviceWriteBps": null,
              "BlkioDeviceReadIOps": null,
              "BlkioDeviceWriteIOps": null,
              "CpuPeriod": 0,
              "CpuQuota": 0,
              "CpuRealtimePeriod": 0,
              "CpuRealtimeRuntime": 0,
              "CpusetCpus": "",
              "CpusetMems": "",
              "Devices": null,
              "DeviceCgroupRules": null,
              "DeviceRequests": null,
              "MemoryReservation": 0,
              "MemorySwap": 0,
              "MemorySwappiness": null,
              "OomKillDisable": null,
              "PidsLimit": null,
              "Ulimits": null,
              "CpuCount": 0,
              "CpuPercent": 0,
              "IOMaximumIOps": 0,
              "IOMaximumBandwidth": 0,
              "Mounts": [
                  {
                      "Type": "volume",
                      "Source": "coolify_dev_postgres_data",
                      "Target": "/var/lib/postgresql/data",
                      "VolumeOptions": {}
                  }
              ],
              "MaskedPaths": [
                  "/proc/asound",
                  "/proc/acpi",
                  "/proc/kcore",
                  "/proc/keys",
                  "/proc/latency_stats",
                  "/proc/timer_list",
                  "/proc/timer_stats",
                  "/proc/sched_debug",
                  "/proc/scsi",
                  "/sys/firmware",
                  "/sys/devices/virtual/powercap"
              ],
              "ReadonlyPaths": [
                  "/proc/bus",
                  "/proc/fs",
                  "/proc/irq",
                  "/proc/sys",
                  "/proc/sysrq-trigger"
              ]
          },
          "GraphDriver": {
              "Data": {
                  "LowerDir": "/var/lib/docker/overlay2/4a03d9a49852aeb72cd14417b5122d5b45bb1f8f51c2644568dca8ad3c263a92-init/diff:/var/lib/docker/overlay2/eea7d1cf26dc92bf884306de3cc589cfdfe0eedb8429030c89cdeb2e8b2c27dd/diff:/var/lib/docker/overlay2/d15ed074d3ab9b42ca38bf18310826afd6155263bc5e897b9182790538b17a54/diff:/var/lib/docker/overlay2/f0ef521fb8a7b9a62d9975bf5b8329895d4aa8d0b10591ad99b5f4d4898b85fe/diff:/var/lib/docker/overlay2/11e83afbece0e9b0e14040f1488f8261e2829cda6b9ebbe3acf042e73b89170b/diff:/var/lib/docker/overlay2/e098a4be50ff4cac048956f4da13b1cdd2a5a768589b5d0d159ab6dcd751919b/diff:/var/lib/docker/overlay2/c9693cd93928bcc8b3f22d90c59f46560fa14a66ad023e4b52e3ae80fa2cd852/diff:/var/lib/docker/overlay2/0d1e07c496139e1ce46ed1137f2d8ae555f02c00e7093ea6026721d8c349c7bd/diff:/var/lib/docker/overlay2/a75a4a3040d8aaa1996cec1c6c0137699f5c2d3deeefa0db684fe0e1d0d78173/diff:/var/lib/docker/overlay2/9709d71704fb9654bb8a2665f989e3559702e58150f27d3768edd994c53fb079/diff:/var/lib/docker/overlay2/75b02083af6cbeb1d90a53d9ad1fffa04671a8ef9068a11e84f1ec1ec102bfad/diff:/var/lib/docker/overlay2/2b13fe91ba5fbbbd5c7077a0f908d863d0c55f42e060be5cca5f51e24e395a29/diff",
                  "MergedDir": "/var/lib/docker/overlay2/4a03d9a49852aeb72cd14417b5122d5b45bb1f8f51c2644568dca8ad3c263a92/merged",
                  "UpperDir": "/var/lib/docker/overlay2/4a03d9a49852aeb72cd14417b5122d5b45bb1f8f51c2644568dca8ad3c263a92/diff",
                  "WorkDir": "/var/lib/docker/overlay2/4a03d9a49852aeb72cd14417b5122d5b45bb1f8f51c2644568dca8ad3c263a92/work"
              },
              "Name": "overlay2"
          },
          "Mounts": [
              {
                  "Type": "volume",
                  "Name": "coolify_dev_postgres_data",
                  "Source": "/var/lib/docker/volumes/coolify_dev_postgres_data/_data",
                  "Destination": "/var/lib/postgresql/data",
                  "Driver": "local",
                  "Mode": "z",
                  "RW": true,
                  "Propagation": ""
              }
          ],
          "Config": {
              "Hostname": "c9248632fb1f",
              "Domainname": "",
              "User": "",
              "AttachStdin": false,
              "AttachStdout": true,
              "AttachStderr": true,
              "ExposedPorts": {
                  "5432/tcp": {}
              },
              "Tty": false,
              "OpenStdin": false,
              "StdinOnce": false,
              "Env": [
                  "RAY_ENABLED=true=123",
                  "REGISTRY_URL=docker.io",
                  "SUBSCRIPTION_PROVIDER=stripe",
                  "TELESCOPE_ENABLED=false",
                  "POSTGRES_HOST_AUTH_METHOD=trust",
                  "DB_PASSWORD=password",
                  "SSH_MUX_ENABLED=true",
                  "SELF_HOSTED=false",
                  "APP_DEBUG=true",
                  "DB_HOST=host.docker.internal",
                  "POSTGRES_DB=coolify",
                  "APP_KEY=base64:8VEfVNVkXQ9mH2L33WBWNMF4eQ0BWD5CTzB9mIxcl+k=",
                  "DEBUGBAR_ENABLED=false",
                  "APP_ID=development",
                  "DB_DATABASE=coolify",
                  "DUSK_DRIVER_URL=http://selenium:4444",
                  "DB_USERNAME=coolify",
                  "APP_NAME=Coolify Development",
                  "APP_PORT=8000",
                  "DB_PORT=5432",
                  "APP_URL=http://localhost",
                  "APP_ENV=local",
                  "PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin",
                  "GOSU_VERSION=1.17",
                  "LANG=en_US.utf8",
                  "PG_MAJOR=15",
                  "PG_VERSION=15.13",
                  "PG_SHA256=4f62e133d22ea08a0401b0840920e26698644d01a80c34341fb732dd0a90ca5d",
                  "DOCKER_PG_LLVM_DEPS=llvm19-dev \t\tclang19",
                  "PGDATA=/var/lib/postgresql/data"
              ],
              "Cmd": [
                  "postgres"
              ],
              "Image": "postgres:15-alpine",
              "Volumes": {
                  "/var/lib/postgresql/data": {}
              },
              "WorkingDir": "/",
              "Entrypoint": [
                  "docker-entrypoint.sh"
              ],
              "OnBuild": null,
              "Labels": {
                  "com.docker.compose.config-hash": "56325981c5d891690fff628668ace4c434c4e457c91d85a0994f35a2409efd05",
                  "com.docker.compose.container-number": "1",
                  "com.docker.compose.depends_on": "",
                  "com.docker.compose.image": "sha256:4100a24644378a24cdbe3def6fc2346999c53d87b12180c221ebb17f05259948",
                  "com.docker.compose.oneoff": "False",
                  "com.docker.compose.project": "coolify",
                  "com.docker.compose.project.config_files": "/Users/heyandras/devel/coolify/docker-compose.yml,/Users/heyandras/devel/coolify/docker-compose.dev.yml",
                  "com.docker.compose.project.working_dir": "/Users/heyandras/devel/coolify",
                  "com.docker.compose.service": "postgres",
                  "com.docker.compose.version": "2.32.4"
              },
              "StopSignal": "SIGINT"
          },
          "NetworkSettings": {
              "Bridge": "",
              "SandboxID": "8e341f80f5ea70fc7ab183d7cb1f7fe1032b9d98214b0d51665259cc7ebff355",
              "SandboxKey": "/var/run/docker/netns/8e341f80f5ea",
              "Ports": {
                  "5432/tcp": [
                      {
                          "HostIp": "0.0.0.0",
                          "HostPort": "5432"
                      },
                      {
                          "HostIp": "::",
                          "HostPort": "5432"
                      }
                  ]
              },
              "HairpinMode": false,
              "LinkLocalIPv6Address": "",
              "LinkLocalIPv6PrefixLen": 0,
              "SecondaryIPAddresses": null,
              "SecondaryIPv6Addresses": null,
              "EndpointID": "",
              "Gateway": "",
              "GlobalIPv6Address": "",
              "GlobalIPv6PrefixLen": 0,
              "IPAddress": "",
              "IPPrefixLen": 0,
              "IPv6Gateway": "",
              "MacAddress": "",
              "Networks": {
                  "coolify": {
                      "IPAMConfig": null,
                      "Links": null,
                      "Aliases": [
                          "coolify-db",
                          "postgres"
                      ],
                      "MacAddress": "02:42:c0:a8:61:02",
                      "DriverOpts": null,
                      "NetworkID": "be1908fb78d9ae5f82d294f8943e0dc597135abbe335a5286e434f4989fd0b3f",
                      "EndpointID": "40440f9c3f3018bb88af01bd198c3640f7ae3f296010dbe645b3725855aef72f",
                      "Gateway": "192.168.97.1",
                      "IPAddress": "192.168.97.2",
                      "IPPrefixLen": 24,
                      "IPv6Gateway": "",
                      "GlobalIPv6Address": "",
                      "GlobalIPv6PrefixLen": 0,
                      "DNSNames": [
                          "coolify-db",
                          "postgres",
                          "c9248632fb1f"
                      ]
                  }
              }
          }
      }
  ]';
    $envs = format_docker_envs_to_json($data);
    $this->assertEquals('true=123', $envs->get('RAY_ENABLED'));
});
