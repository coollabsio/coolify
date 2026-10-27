# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- Replace the release process
    - Decide the version, the releases and the moving tags with `scripts/version.sh`, which also reports what a push publishes

### Removed

- Remove the git-cliff workflow that generated `CHANGELOG.md`, it is written by hand
