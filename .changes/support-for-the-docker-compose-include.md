---
type: added
---
Support for the top-level `include` element in the compose file of Docker Compose applications

- When Coolify loads the compose file, it reads the included files from the repository, merges them with `docker compose config`, and saves one compose file. The parser, the validation, and the deployment use that file
- Nested includes, `path` lists, `project_directory`, and `env_file` work. Relative paths in included files, such as `build` contexts and bind mounts, point to the folder of the included file
- An included file must be in the repository. Coolify rejects absolute paths, paths that go above the repository root, symlinks, variables in include paths, and remote includes (Git, HTTP, and OCI)
- The server needs Docker Compose 2.20 or later
- Compose files without `include` do not change
- Thanks to @TupiC for the first version in #8850
