---
id: task-00001
title: Implement Docker build caching for Coolify staging builds
status: To Do
assignee: []
created_date: '2025-08-26 12:15'
updated_date: '2025-08-26 12:16'
labels:
  - heyandras
  - performance
  - docker
  - ci-cd
  - build-optimization
dependencies: []
priority: high
---

## Description

Implement comprehensive Docker build caching to reduce staging build times by 50-70% through BuildKit cache mounts for dependencies and GitHub Actions registry caching. This optimization will significantly reduce build times from ~10-15 minutes to ~3-5 minutes, decrease network usage, and lower GitHub Actions costs.

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 Docker BuildKit cache mounts are added to Composer dependency installation in production Dockerfile
- [ ] #2 Docker BuildKit cache mounts are added to NPM dependency installation in production Dockerfile
- [ ] #3 GitHub Actions BuildX setup is configured for both AMD64 and AARCH64 jobs
- [ ] #4 Registry cache-from and cache-to configurations are implemented for both architecture builds
- [ ] #5 Build time reduction of at least 40% is achieved in staging builds
- [ ] #6 GitHub Actions minutes consumption is reduced compared to baseline
- [ ] #7 All existing build functionality remains intact with no regressions
<!-- AC:END -->

## Implementation Plan

1. Modify docker/production/Dockerfile to add BuildKit cache mounts:
   - Add cache mount for Composer dependencies at line 30: --mount=type=cache,target=/var/www/.composer/cache
   - Add cache mount for NPM dependencies at line 41: --mount=type=cache,target=/root/.npm

2. Update .github/workflows/coolify-staging-build.yml for AMD64 job:
   - Add docker/setup-buildx-action@v3 step after checkout
   - Configure cache-from and cache-to parameters in build-push-action
   - Use registry caching with buildcache-amd64 tags

3. Update .github/workflows/coolify-staging-build.yml for AARCH64 job:
   - Add docker/setup-buildx-action@v3 step after checkout  
   - Configure cache-from and cache-to parameters in build-push-action
   - Use registry caching with buildcache-aarch64 tags

4. Test implementation:
   - Measure baseline build times before changes
   - Deploy changes and monitor initial build (will be slower due to cache population)
   - Measure subsequent build times to verify 40%+ improvement
   - Validate all build outputs and functionality remain unchanged

5. Monitor and validate:
   - Track GitHub Actions minutes consumption reduction
   - Ensure Docker registry storage usage is reasonable
   - Verify no build failures or regressions introduced
