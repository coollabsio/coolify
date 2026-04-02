import Docker from 'dockerode';

/**
 * Represents an IPAM config entry for a Docker network, aligning with Docker API's IPAMConfig.
 * This specifies subnet, IP range, gateway, and optional auxiliary addresses for an IP pool.
 */
export interface DockerIPAMConfig {
  Subnet?: string;
  IPRange?: string;
  Gateway?: string;
  AuxiliaryAddresses?: { [key: string]: string };
}

/**
 * Represents the IPAM options for a Docker network, which can include multiple DockerIPAMConfig entries.
 * Aligns with Docker API's IPAM.
 */
export interface DockerIPAM {
  Driver?: string;
  Config?: DockerIPAMConfig[];
}

/**
 * Interface for the options required to create a Docker network, extending Dockerode's NetworkCreateOptions.
 * This allows Coolify to specify advanced network configurations including custom drivers, IPAM, and IPv6.
 */
export interface CoolifyNetworkCreateOptions extends Omit<Docker.NetworkCreateOptions, 'IPAM'> {
  Name: string;
  Driver?: string;
  Options?: { [key: string]: string }; // Maps to DriverOpts
  IPAM?: DockerIPAM; // Custom IPAM interface
  Attachable?: boolean;
  Internal?: boolean;
  EnableIPv6?: boolean;
  Labels?: { [key: string]: string };
}