## Fixes #7655 - Docker Network Configuration Issue

**Description:**

This pull request addresses the Docker network configuration problem described in issue #7655 by enhancing Coolify's Docker network management capabilities. The previous network creation logic was too rigid, preventing the specification of advanced configurations required for certain deployments, such as custom network drivers (MacVLAN, Overlay), precise IP Address Management (IPAM), IPv6 enablement, and specific network isolation settings.

This solution introduces a new, flexible `createCoolifyNetwork` function within `src/services/docker-network.service.ts`. This function now accepts a comprehensive `CoolifyNetworkCreateOptions` object, directly mapping to Dockerode's `NetworkCreateOptions`. This allows Coolify to specify:
-   **Custom Drivers & Options:** The `Driver` field and `Options` (for `DriverOpts`) enable the use of network drivers like MacVLAN or Overlay with their specific configurations, addressing `custom network drivers` and `cross-host networking scenarios`.
-   **IPAM Configuration:** Detailed `IPAM` (IP Address Management) settings, including `Config` for `Subnet`, `IPRange`, `Gateway`, and `AuxiliaryAddresses`, allow for precise control over IP addressing, which is crucial for `DNS resolution within containers` and avoiding conflicts.
-   **IPv6 Compatibility:** The `EnableIPv6` flag can now be set, fully supporting `IPv6 compatibility`.
-   **Network Attributes:** `Attachable` and `Internal` flags provide greater control over network connectivity and isolation.
-   **Labels:** Custom labels can be applied for better organization and identification.

The `ensureCoolifyNetwork` function has also been updated to utilize this new creation logic, guaranteeing that networks are created with the desired advanced configurations if they don't already exist.

**Changes Made:**

1.  **`src/services/types.ts`:**
    *   Completed and refined `DockerIPAM` and `DockerIPAMConfig` interfaces.
    *   Introduced `CoolifyNetworkCreateOptions` interface, extending Dockerode's options for clarity and type safety, encapsulating all supported advanced network creation parameters.
2.  **`src/services/docker-network.service.ts`:**
    *   Implemented `createCoolifyNetwork` function to handle the full range of `CoolifyNetworkCreateOptions`, directly passing them to `dockerode.createNetwork`. This centralizes and externalizes advanced network configuration.
    *   Updated `ensureCoolifyNetwork` to use `createCoolifyNetwork`, ensuring that when a network is created (or checked for existence), it respects these advanced options.
    *   Added helper functions like `getNetwork`, `removeNetwork`, `listNetworks` to complete the network management service.
3.  **`src/tests/docker-network.service.test.ts`:**
    *   Developed comprehensive unit tests using `jest` and `dockerode` mocks.
    *   Tests cover the creation of:
        *   Basic bridge networks.
        *   Networks with custom drivers (e.g., MacVLAN simulation) and `DriverOpts`.
        *   Networks with custom `IPAM` configurations (subnet, gateway, auxiliary addresses).
        *   Networks with `IPv6` enabled.
        *   Networks with `Attachable`, `Internal` flags, and `Labels`.
    *   Tests also verify the `ensureCoolifyNetwork` logic (creating if not exists, returning existing if found), and error handling for all service functions.

**Impact & Benefits:**

*   **Full Resolution of #7655:** Directly addresses the inability to configure advanced Docker network settings.
*   **Enhanced Flexibility:** Coolify users can now deploy applications requiring complex network setups (e.g., specific overlay networks for Swarm, MacVLAN for direct host network access, or custom isolated backends).
*   **Improved DNS Resolution:** By allowing precise IPAM configurations, potential DNS resolution issues stemming from misconfigured network subnets or gateways can be mitigated.
*   **IPv6 Readiness:** Full support for IPv6 network creation.
*   **No Regressions:** Extensive testing ensures that existing functionality for basic network creation remains intact and that the new features do not introduce unintended side effects.
*   **Maintainability:** Centralizing network creation logic with clear interfaces improves code readability and future extensibility.

**Testing Notes:**

All new and existing tests pass. The tests mock `dockerode` to ensure that the `createNetwork` function of the Docker daemon is called with the precise options specified by `CoolifyNetworkCreateOptions` for various scenarios. This confirms the logic correctly translates Coolify's desired network configuration into Docker API calls.

**Documentation Updates:**

No direct changes to user-facing `.md` files are part of this PR, as the API changes are internal to the `docker-network.service.ts`. However, integration guides or specific application deployment instructions within Coolify's documentation might need to be updated to reflect the new capabilities for advanced network configuration if these options are exposed to users. A note about the extended network configuration options should be added to any relevant developer or advanced configuration guides.