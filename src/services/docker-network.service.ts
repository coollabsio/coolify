import Docker from 'dockerode';
import { CoolifyNetworkCreateOptions, DockerIPAMConfig, DockerIPAM } from './types';

// This would typically be initialized in a central place in Coolify, e.g., an app service.
// For this standalone example, we'll instantiate it directly.
const docker = new Docker({ socketPath: '/var/run/docker.sock' });

/**
 * Service for managing Docker network configurations within Coolify.
 * This includes creating, listing, and removing networks with advanced options.
 */
export class DockerNetworkService {
  private docker: Docker;

  constructor(dockerInstance: Docker) {
    this.docker = dockerInstance;
  }

  /**
   * Ensures a Docker network exists with the specified configuration.
   * If the network does not exist, it will be created.
   * If it exists, it will return the existing network information without modifying it.
   *
   * @param options - The configuration options for the network.
   * @returns A promise that resolves to the Docker Network object.
   * @throws An error if network creation fails.
   */
  public async ensureCoolifyNetwork(options: CoolifyNetworkCreateOptions): Promise<Docker.Network> {
    const { Name, ...networkOptions } = options;

    try {
      const existingNetwork = await this.getNetwork(Name);
      if (existingNetwork) {
        console.log(`Network '${Name}' already exists.`);
        return existingNetwork;
      }
    } catch (error: any) {
      if (!error.message.includes('No such network')) {
        // Re-throw if it's an error other than "network not found"
        throw new Error(`Failed to check for existing network '${Name}': ${error.message}`);
      }
      // If "No such network", proceed to create it
    }

    console.log(`Creating Docker network '${Name}' with options:`, networkOptions);
    try {
      const network = await this.createCoolifyNetwork(options);
      console.log(`Successfully created network '${Name}' (ID: ${network.id})`);
      return network;
    } catch (error: any) {
      console.error(`Error creating network '${Name}':`, error);
      throw new Error(`Could not create network '${Name}': ${error.message}`);
    }
  }

  /**
   * Creates a Docker network with highly customizable options, including support for
   * custom drivers, IPAM configurations, IPv6, and network isolation settings.
   * This directly maps to Dockerode's NetworkCreateOptions for maximum flexibility.
   *
   * @param options - The configuration options for creating the Docker network.
   * @returns A promise that resolves to the created Docker Network object.
   * @throws An error if the network creation fails.
   */
  public async createCoolifyNetwork(options: CoolifyNetworkCreateOptions): Promise<Docker.Network> {
    const { Name, Driver, Options, IPAM, Attachable, Internal, EnableIPv6, Labels } = options;

    const dockerodeOptions: Docker.NetworkCreateOptions = {
      Name: Name,
      Driver: Driver || 'bridge', // Default to bridge if not specified
      Options: Options, // DriverOpts for custom drivers
      Attachable: Attachable,
      Internal: Internal,
      EnableIPv6: EnableIPv6,
      Labels: Labels,
    };

    if (IPAM) {
      dockerodeOptions.IPAM = {
        Driver: IPAM.Driver || 'default',
        Config: IPAM.Config?.map(cfg => ({
          Subnet: cfg.Subnet,
          IPRange: cfg.IPRange,
          Gateway: cfg.Gateway,
          AuxiliaryAddresses: cfg.AuxiliaryAddresses,
        })),
      };
    }

    try {
      const network = await this.docker.createNetwork(dockerodeOptions);
      return network;
    } catch (error: any) {
      console.error(`Failed to create Docker network '${Name}':`, error);
      throw error;
    }
  }

  /**
   * Retrieves a Docker network by its name.
   * @param name - The name of the network to retrieve.
   * @returns A promise that resolves to the Docker Network object, or undefined if not found.
   */
  public async getNetwork(name: string): Promise<Docker.Network | undefined> {
    try {
      const network = this.docker.getNetwork(name);
      await network.inspect(); // This will throw if the network does not exist
      return network;
    } catch (error: any) {
      if (error.statusCode === 404 || error.message.includes('No such network')) {
        return undefined; // Network not found
      }
      throw error; // Other error occurred
    }
  }

  /**
   * Removes a Docker network by its ID or name.
   * @param networkIdOrName - The ID or name of the network to remove.
   * @returns A promise that resolves when the network is successfully removed.
   */
  public async removeNetwork(networkIdOrName: string): Promise<void> {
    try {
      const network = this.docker.getNetwork(networkIdOrName);
      await network.remove();
      console.log(`Successfully removed network '${networkIdOrName}'.`);
    } catch (error: any) {
      console.error(`Failed to remove network '${networkIdOrName}':`, error);
      throw error;
    }
  }

  /**
   * Lists all Docker networks.
   * @returns A promise that resolves to an array of Docker NetworkInfo objects.
   */
  public async listNetworks(): Promise<Docker.NetworkInfo[]> {
    try {
      const networks = await this.docker.listNetworks();
      return networks;
    } catch (error: any) {
      console.error('Failed to list Docker networks:', error);
      throw error;
    }
  }
}

// Export a singleton instance if preferred for Coolify's application architecture
// export const dockerNetworkService = new DockerNetworkService(docker);