<?php

namespace App\Exceptions;

/**
 * Stops a fork pull request preview deployment whose commit waits for an admin approval.
 * The deployment ends as cancelled, not as failed.
 */
class PreviewApprovalRequiredException extends DeploymentException {}
