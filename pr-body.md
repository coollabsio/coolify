### Changes
This PR adds a new setting that allows users to self-register via OAuth providers even when general registration is disabled.

**What was added:**
- New `is_oauth_registration_enabled` setting in instance_settings table
- New checkbox "OAuth Registration Allowed" in Settings > Advanced
- Updated OAuth callback logic to check both registration settings

**Why this is needed:**
Currently, OAuth registration is tied to the general registration setting. This means if you want to use an identity provider (like Authentik, Keycloak, or Okta) to control who can access your Coolify instance, you must enable general registration, which also allows anyone to create accounts with passwords.

With this change, administrators can:
- Disable general registration (blocking password-based signups)
- Enable OAuth registration only (users must authenticate via the configured OAuth provider)
- Control access via external identity providers

### Issue 
> - Resolves #8042

### Category
> - [x] New feature

### Screenshots or Video (if applicable)
**Settings > Advanced page showing the new OAuth Registration checkbox:**

The new setting appears under "Registration Settings" with a helpful tooltip explaining its purpose. When enabled, users can register through OAuth providers even when the general "Registration Allowed" setting is disabled.

### AI Usage
> - [x] AI is NOT used in the process of creating this PR

### Steps to Test
> - Step 1 – Configure an OAuth provider in Settings > OAuth (e.g., GitHub)
> - Step 2 – Go to Settings > Advanced
> - Step 3 – Disable "Registration Allowed" (general registration)
> - Step 4 – Enable "OAuth Registration Allowed"
> - Step 5 – Log out and try to register via OAuth - registration should succeed
> - Step 6 – Verify that the manual registration form is not available (general registration is disabled)
> - Step 7 – Disable "OAuth Registration Allowed" as well
> - Step 8 – Try to register via OAuth again - should get 403 error "Registration is disabled"

### Contributor Agreement
> [!IMPORTANT]
 > - [x] I have read and understood the [contributor guidelines](https://github.com/coollabsio/coolify/blob/v4.x/CONTRIBUTING.md). If I have failed to follow any guideline, I understand that this PR may be closed without review.
 > - [x] I have tested the changes thoroughly and am confident that they will work as expected without issues when the maintainer tests them
