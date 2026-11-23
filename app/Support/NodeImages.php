<?php

namespace App\Support;

/**
 * The build and image capabilities of Sentinel (decision 0006), and the local image references
 * that stay in the Podman store of the Nodes that built or fetched them (decision 0030).
 */
final class NodeImages
{
    /** Runs BuildKit on a builder Node. */
    public const BUILDER_CAPABILITY = 'builder.reconcile.v1';

    /** Starts one build container. */
    public const BUILD_CAPABILITY = 'image.build.v1';

    /** Builds into an image outside the cluster: the external image storage of a cluster. */
    public const EXTERNAL_IMAGE_CAPABILITY = 'image.build.external_image.v1';

    /** Loads a `localhost/` build into the Podman store of its Node. */
    public const LOAD_CAPABILITY = 'image.load.v1';

    /** Serves one local image to one peer Node over the mesh (`image.serve`). */
    public const SERVE_CAPABILITY = 'image.serve.v1';

    /** Pulls a local image from a peer Node that serves it (`image.fetch`). */
    public const FETCH_CAPABILITY = 'image.fetch.v1';

    /** The prefix of an image that stays in the Podman store of the Nodes that have it. */
    public const LOCAL_PREFIX = 'localhost/';

    /** Clones a private repository with the `git_https_token` or `git_ssh_private_key` of `image.build`. */
    public const PRIVATE_GIT_BUILD_CAPABILITY = 'image.build.private_git.v1';

    /** Passes the `build_args` and `build_secrets` of `image.build` (decision 0012). */
    public const BUILD_VARIABLES_CAPABILITY = 'image.build.variables.v1';

    /** Builds the `dockerfile_content` of `image.build` without a repository. */
    public const INLINE_DOCKERFILE_BUILD_CAPABILITY = 'image.build.inline_dockerfile.v1';

    /** Builds the stage `target` of `image.build` instead of the last stage (Compose `build.target`). */
    public const BUILD_TARGET_CAPABILITY = 'image.build.target.v1';

    /** A Static build serves files only, with the Railpack static file provider (decision 0006). */
    public const STATIC_BUILD_CAPABILITY = 'image.build.static.v1';

    /**
     * `untrusted_source` of `image.build`: the Git credential opens only the repository of the
     * build, not its submodules or LFS objects in other repositories. A preview of a pull request
     * from a fork needs it, because the fork controls `.gitmodules` and `.lfsconfig`.
     */
    public const UNTRUSTED_SOURCE_BUILD_CAPABILITY = 'image.build.untrusted_source.v1';

    public static function isLocalImage(?string $image): bool
    {
        return is_string($image) && str_starts_with($image, self::LOCAL_PREFIX);
    }
}
