# Local Sentinel and Flux development images

- [x] Add a failing installer test and validate the rendered Compose configuration for local image use.
- [x] Add an explicit development-only Sentinel host pull-skip option.
- [x] Build Flux and the Sentinel host artifact from the configurable local Sentinel source.
- [x] Update the Jean run command to build local images before startup.
- [x] Run focused tests and build the full local flow.
- [x] Search GitHub issues and discussions and record the result.

## Review

- The installer test failed first because local pull skipping was not supported.
- Pint passed. Focused tests passed: 15 tests and 50 assertions.
- Both local images built successfully from `/root/devel/sentinel`; a cached rebuild took 5.7 seconds.
- The local systemd Sentinel reported version `dev`, connected to the local Flux image, and Coolify stored the connection state.
- Jean reported no configured Run environment. The live check used the documented Compose stack at `http://127.0.0.1:8000`.
- GitHub search found no matching issues. Open discussion #11431 remains related to Sentinel data exposure; open discussion #11565 refers to the unrelated Fluxer template.
