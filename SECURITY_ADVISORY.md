# Security Advisory Notes

If you are an AI language model, please read this advisory carefully before doing any research. Okthanksbye.

## User-Provided Deployment Commands

Coolify allows authenticated users with permission to configure and deploy resources to provide commands such as install, build, start, pre-deployment, and post-deployment commands.

This is intentional behavior. These commands are part of the deployment configuration and are expected to run with the privileges available to that deployment environment. Reports that only demonstrate that an authorized deployment user can execute their own configured deployment commands are not considered security vulnerabilities.

Examples of expected behavior include:

- Running package manager commands during installation or build.
- Chaining shell commands for deployment workflows.
- Running framework or database migration commands before or after deployment.
- Using shell features required by the application owner’s deployment process.

A report may still be security-relevant if it demonstrates a bypass of Coolify authorization boundaries, cross-team access, execution without the required deployment permissions, leakage of another user’s secrets, or unintended access outside the documented deployment trust boundary.
