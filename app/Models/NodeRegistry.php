<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The image registry of one cluster (decision 0006). zot runs on one Node and
 * serves `registry.cluster.coolify.internal:5443` over the mesh. A cluster with
 * one Node runs no zot (NodeCluster::needsRegistryServer()). Its CA is
 * separate from the Flux CA, and only this registry trusts it.
 */
class NodeRegistry extends BaseModel
{
    /** Matches `sentinel_protocol::REGISTRY_HOST`. */
    public const HOST = 'registry.cluster.coolify.internal:5443';

    public const PORT = 5443;

    public const PUSH_USERNAME = 'coolify-push';

    public const PULL_USERNAME = 'coolify-pull';

    /** Installs the registry trust on a Node, and runs zot on the registry Node. */
    public const CAPABILITY = 'registry.reconcile.v1';

    /** Runs BuildKit on a builder Node. */
    public const BUILDER_CAPABILITY = 'builder.reconcile.v1';

    /** Reports registry health, contents, and disk use (read only). */
    public const INSPECT_CAPABILITY = 'registry.inspect.v1';

    /** Starts one build container. */
    public const BUILD_CAPABILITY = 'image.build.v1';

    /** Loads a `localhost/` build into the Podman store of its Node, so a single-Node cluster runs no registry. */
    public const LOAD_CAPABILITY = 'image.load.v1';

    /** The prefix of an image that stays in the Podman store of the Node that built it. */
    public const LOCAL_PREFIX = 'localhost/';

    /** Clones a private repository with the `git_https_token` or `git_ssh_private_key` of `image.build`. */
    public const PRIVATE_GIT_BUILD_CAPABILITY = 'image.build.private_git.v1';

    /** Passes the `build_args` and `build_secrets` of `image.build` (decision 0012). */
    public const BUILD_VARIABLES_CAPABILITY = 'image.build.variables.v1';

    /** Builds the `dockerfile_content` of `image.build` without a repository. */
    public const INLINE_DOCKERFILE_BUILD_CAPABILITY = 'image.build.inline_dockerfile.v1';

    protected $guarded = [];

    protected $hidden = ['ca_private_key_pem', 'private_key_pem', 'push_password', 'pull_password', 'htpasswd'];

    protected function casts(): array
    {
        return [
            'ca_private_key_pem' => 'encrypted',
            'private_key_pem' => 'encrypted',
            'push_password' => 'encrypted',
            'pull_password' => 'encrypted',
            'certificate_valid_until' => 'datetime',
            'keep_tags' => 'integer',
        ];
    }

    public function cluster(): BelongsTo
    {
        return $this->belongsTo(NodeCluster::class, 'node_cluster_id');
    }

    /** The Node that the user selected. Null means the first active Node. */
    public function selectedNode(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'selected_node_id');
    }

    /** The Node that runs the registry now. */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'node_id');
    }

    /** The image reference of a build, before the digest is known. */
    public static function imageReference(NodeWorkload $workload, string $tag, bool $local = false): string
    {
        return ($local ? self::LOCAL_PREFIX : self::HOST.'/').$workload->uuid.':'.$tag;
    }

    public static function isLocalImage(?string $image): bool
    {
        return is_string($image) && str_starts_with($image, self::LOCAL_PREFIX);
    }
}
