import { DockerNetworkService } from '../services/docker-network.service';
import { CoolifyNetworkCreateOptions } from '../services/types';
import Docker from 'dockerode';

// Mock Dockerode for testing
const mockDockerode = {
  createNetwork: jest.fn(),
  getNetwork: jest.fn(),
  listNetworks: jest.fn(),
};

// Mock Docker.Network interface methods
const mockNetworkInspect = jest.fn();
const mockNetworkRemove = jest.fn();

// Define a default mock network object for successful lookups
const mockExistingNetwork = {
  id: 'mockNetworkId123',
  name: 'existing-network',
  inspect: mockNetworkInspect,
  remove: mockNetworkRemove,
};

describe('DockerNetworkService', () => {
  let service: DockerNetworkService;

  beforeEach(() => {
    jest.clearAllMocks();
    service = new DockerNetworkService(mockDockerode as unknown as Docker);

    // Default mock for getNetwork to simulate "not found"
    mockDockerode.getNetwork.mockImplementation((name: string) => {
      if (name === mockExistingNetwork.name) {
        return mockExistingNetwork;
      }
      throw { statusCode: 404, message: 'No such network: ' + name };
    });
    mockNetworkInspect.mockResolvedValue(mockExistingNetwork);
    mockNetworkRemove.mockResolvedValue({});
  });

  describe('createCoolifyNetwork', () => {
    it('should create a basic bridge network', async () => {
      const options: CoolifyNetworkCreateOptions = {
        Name: 'test-bridge-network',
      };

      mockDockerode.createNetwork.mockResolvedValue({ id: 'networkId1', name: options.Name });

      const network = await service.createCoolifyNetwork(options);

      expect(mockDockerode.createNetwork).toHaveBeenCalledWith(
        expect.objectContaining({
          Name: 'test-bridge-network',
          Driver: 'bridge',
          Attachable: undefined,
          Internal: undefined,
          EnableIPv6: undefined,
          Labels: undefined,
          IPAM: undefined,
          Options: undefined,
        })
      );
      expect(network.id).toBe('networkId1');
    });

    it('should create a network with a custom driver and options', async () => {
      const options: CoolifyNetworkCreateOptions = {
        Name: 'test-macvlan-network',
        Driver: 'macvlan',
        Options: {
          'parent': 'eth0',
          'macvlan_mode': 'bridge',
        },
      };

      mockDockerode.createNetwork.mockResolvedValue({ id: 'networkId2', name: options.Name });

      await service.createCoolifyNetwork(options);

      expect(mockDockerode.createNetwork).toHaveBeenCalledWith(
        expect.objectContaining({
          Name: 'test-macvlan-network',
          Driver: 'macvlan',
          Options: {
            parent: 'eth0',
            macvlan_mode: 'bridge',
          },
        })
      );
    });

    it('should create a network with custom IPAM configuration', async () => {
      const options: CoolifyNetworkCreateOptions = {
        Name: 'test-custom-ipam',
        IPAM: {
          Driver: 'default',
          Config: [
            {
              Subnet: '172.20.0.0/16',
              IPRange: '172.20.10.0/24',
              Gateway: '172.20.0.1',
              AuxiliaryAddresses: {
                'host1': '172.20.0.10',
              },
            },
          ],
        },
      };

      mockDockerode.createNetwork.mockResolvedValue({ id: 'networkId3', name: options.Name });

      await service.createCoolifyNetwork(options);

      expect(mockDockerode.createNetwork).toHaveBeenCalledWith(
        expect.objectContaining({
          Name: 'test-custom-ipam',
          IPAM: {
            Driver: 'default',
            Config: [
              {
                Subnet: '172.20.0.0/16',
                IPRange: '172.20.10.0/24',
                Gateway: '172.20.0.1',
                AuxiliaryAddresses: {
                  host1: '172.20.0.10',
                },
              },
            ],
          },
        })
      );
    });

    it('should create a network with IPv6 enabled', async () => {
      const options: CoolifyNetworkCreateOptions = {
        Name: 'test-ipv6-network',
        EnableIPv6: true,
        IPAM: {
          Config: [
            {
              Subnet: '2001:db8:abcd::/64',
            },
          ],
        },
      };

      mockDockerode.createNetwork.mockResolvedValue({ id: 'networkId4', name: options.Name });

      await service.createCoolifyNetwork(options);

      expect(mockDockerode.createNetwork).toHaveBeenCalledWith(
        expect.objectContaining({
          Name: 'test-ipv6-network',
          EnableIPv6: true,
          IPAM: {
            Driver: 'default',
            Config: [
              {
                Subnet: '2001:db8:abcd::/64',
              },
            ],
          },
        })
      );
    });

    it('should create an attachable and internal network with labels', async () => {
      const options: CoolifyNetworkCreateOptions = {
        Name: 'test-isolated-attachable',
        Attachable: true,
        Internal: true,
        Labels: {
          'coolify.project': 'my-app',
          'coolify.env': 'production',
        },
      };

      mockDockerode.createNetwork.mockResolvedValue({ id: 'networkId5', name: options.Name });

      await service.createCoolifyNetwork(options);

      expect(mockDockerode.createNetwork).toHaveBeenCalledWith(
        expect.objectContaining({
          Name: 'test-isolated-attachable',
          Attachable: true,
          Internal: true,
          Labels: {
            'coolify.project': 'my-app',
            'coolify.env': 'production',
          },
        })
      );
    });

    it('should throw an error if Docker network creation fails', async () => {
      const options: CoolifyNetworkCreateOptions = {
        Name: 'fail-network',
      };
      const errorMessage = 'Network create failed for some reason.';
      mockDockerode.createNetwork.mockRejectedValue(new Error(errorMessage));

      await expect(service.createCoolifyNetwork(options)).rejects.toThrow(errorMessage);
    });
  });

  describe('ensureCoolifyNetwork', () => {
    it('should create the network if it does not exist', async () => {
      const options: CoolifyNetworkCreateOptions = {
        Name: 'new-network',
      };
      mockDockerode.createNetwork.mockResolvedValue({ id: 'newNetworkId', name: options.Name });

      const network = await service.ensureCoolifyNetwork(options);

      expect(mockDockerode.getNetwork).toHaveBeenCalledWith(options.Name);
      expect(mockDockerode.createNetwork).toHaveBeenCalledTimes(1);
      expect(network.id).toBe('newNetworkId');
    });

    it('should return existing network if it already exists', async () => {
      const options: CoolifyNetworkCreateOptions = {
        Name: mockExistingNetwork.name,
      };

      const network = await service.ensureCoolifyNetwork(options);

      expect(mockDockerode.getNetwork).toHaveBeenCalledWith(options.Name);
      expect(mockNetworkInspect).toHaveBeenCalledTimes(1); // To confirm it exists
      expect(mockDockerode.createNetwork).not.toHaveBeenCalled();
      expect(network.id).toBe(mockExistingNetwork.id);
    });

    it('should throw an error if network check fails for reasons other than 404', async () => {
      const options: CoolifyNetworkCreateOptions = { Name: 'error-network' };
      const checkError = new Error('Docker API error');
      mockDockerode.getNetwork.mockImplementation(() => {
        throw checkError; // Simulate a general Docker API error during getNetwork
      });

      await expect(service.ensureCoolifyNetwork(options)).rejects.toThrow(
        `Failed to check for existing network 'error-network': Docker API error`
      );
      expect(mockDockerode.createNetwork).not.toHaveBeenCalled();
    });

    it('should throw an error if network creation fails during ensure', async () => {
      const options: CoolifyNetworkCreateOptions = { Name: 'fail-create-network' };
      const createError = new Error('Failed to allocate IP');
      mockDockerode.createNetwork.mockRejectedValue(createError);

      await expect(service.ensureCoolifyNetwork(options)).rejects.toThrow(
        `Could not create network 'fail-create-network': Failed to allocate IP`
      );
    });
  });

  describe('getNetwork', () => {
    it('should return network if found', async () => {
      const network = await service.getNetwork(mockExistingNetwork.name);
      expect(network).toBe(mockExistingNetwork);
      expect(mockNetworkInspect).toHaveBeenCalledTimes(1);
    });

    it('should return undefined if network not found', async () => {
      mockDockerode.getNetwork.mockImplementation((name: string) => {
        throw { statusCode: 404, message: `No such network: ${name}` };
      });
      const network = await service.getNetwork('non-existent-network');
      expect(network).toBeUndefined();
    });

    it('should throw an error if network inspection fails for other reasons', async () => {
      const error = new Error('Permission denied');
      mockNetworkInspect.mockRejectedValue(error);

      await expect(service.getNetwork(mockExistingNetwork.name)).rejects.toThrow(error);
    });
  });

  describe('removeNetwork', () => {
    it('should remove an existing network', async () => {
      mockDockerode.getNetwork.mockReturnValue(mockExistingNetwork);
      await service.removeNetwork(mockExistingNetwork.id);
      expect(mockDockerode.getNetwork).toHaveBeenCalledWith(mockExistingNetwork.id);
      expect(mockNetworkRemove).toHaveBeenCalledTimes(1);
    });

    it('should throw an error if network removal fails', async () => {
      const error = new Error('Network in use');
      mockDockerode.getNetwork.mockReturnValue(mockExistingNetwork);
      mockNetworkRemove.mockRejectedValue(error);

      await expect(service.removeNetwork(mockExistingNetwork.id)).rejects.toThrow(error);
    });
  });

  describe('listNetworks', () => {
    it('should list all networks', async () => {
      const networksList = [{ Name: 'net1' }, { Name: 'net2' }];
      mockDockerode.listNetworks.mockResolvedValue(networksList);

      const result = await service.listNetworks();
      expect(result).toEqual(networksList);
    });

    it('should throw an error if listing networks fails', async () => {
      const error = new Error('Cannot connect to Docker daemon');
      mockDockerode.listNetworks.mockRejectedValue(error);

      await expect(service.listNetworks()).rejects.toThrow(error);
    });
  });
});