---
name: fortify-development
description: 'ACTIVATE when the user works on authentication in Laravel. This includes login, registration, password reset, email verification, two-factor authentication (2FA/TOTP/QR codes/recovery codes), profile updates, password confirmation, or any auth-related routes and controllers. Activate when the user mentions Fortify, auth, authentication, login, register, signup, forgot password, verify email, 2FA, or references app/Actions/Fortify/, CreateNewUser, UpdateUserProfileInformation, FortifyServiceProvider, config/fortify.php, or auth guards. Fortify is the frontend-agnostic authentication backend for Laravel that registers all auth routes and controllers. Also activate when building SPA or headless authentication, customizing login redirects, overriding response contracts like LoginResponse, or configuring login throttling. Do NOT activate for Laravel Passport (OAuth2 API tokens), Socialite (OAuth social login), or non-auth Laravel features.'
license: MIT
metadata:
  author: laravel
---

# Laravel Fortify Development

Fortify is a headless authentication backend that provides authentication routes and controllers for Laravel applications.

## Documentation

Use `search-docs` for detailed Laravel Fortify patterns and documentation.

## Usage

- **Routes**: Use `list-routes` with `only_vendor: true` and `action: "Fortify"` to see all registered endpoints
- **Actions**: Check `app/Actions/Fortify/` for customizable business logic (user creation, password validation, etc.)
- **Config**: See `config/fortify.php` for all options including features, guards, rate limiters, and username field
- **Contracts**: Look in `Laravel\Fortify\Contracts\` for overridable response classes (`LoginResponse`, `LogoutResponse`, etc.)
- **Views**: All view callbacks are set in `FortifyServiceProvider::boot()` using `Fortify::loginView()`, `Fortify::registerView()`, etc.

## Available Features

Enable in `config/fortify.php` features array:

- `Features::registration()` - User registration
- `Features::resetPasswords()` - Password reset via email
- `Features::emailVerification()` - Requires User to implement `MustVerifyEmail`
- `Features::updateProfileInformation()` - Profile updates
- `Features::updatePasswords()` - Password changes
- `Features::twoFactorAuthentication()` - 2FA with QR codes and recovery codes

> Use `search-docs` for feature configuration options and customization patterns.

## Setup Workflows

### Two-Factor Authentication Setup

```
- [ ] Add TwoFactorAuthenticatable trait to User model
- [ ] Enable feature in config/fortify.php
- [ ] If the `*_add_two_factor_columns_to_users_table.php` migration is missing, publish via `php artisan vendor:publish --tag=fortify-migrations` and migrate
- [ ] Set up view callbacks in FortifyServiceProvider
- [ ] Create 2FA management UI
- [ ] Test QR code and recovery codes
```

> Use `search-docs` for TOTP implementation and recovery code handling patterns.

### Email Verification Setup

```
- [ ] Enable emailVerification feature in config
- [ ] Implement MustVerifyEmail interface on User model
- [ ] Set up verifyEmailView callback
- [ ] Add verified middleware to protected routes
- [ ] Test verification email flow
```

> Use `search-docs` for MustVerifyEmail implementation patterns.

### Password Reset Setup

```
- [ ] Enable resetPasswords feature in config
- [ ] Set up requestPasswordResetLinkView callback
- [ ] Set up resetPasswordView callback
- [ ] Define password.reset named route (if views disabled)
- [ ] Test reset email and link flow
```

> Use `search-docs` for custom password reset flow patterns.

### SPA Authentication Setup

```
- [ ] Set 'views' => false in config/fortify.php
- [ ] Install and configure Laravel Sanctum for session-based SPA authentication
- [ ] Use the 'web' guard in config/fortify.php (required for session-based authentication)
- [ ] Set up CSRF token handling
- [ ] Test XHR authentication flows
```

> Use `search-docs` for integration and SPA authentication patterns.

#### Two-Factor Authentication in SPA Mode

When `views` is set to `false`, Fortify returns JSON responses instead of redirects.

If a user attempts to log in and two-factor authentication is enabled, the login request will return a JSON response indicating that a two-factor challenge is required:

```json
{
    "two_factor": true
}
```

## Best Practices

### Custom Authentication Logic

Override authentication behavior using `Fortify::authenticateUsing()` for custom user retrieval or `Fortify::authenticateThrough()` to customize the authentication pipeline. Override response contracts in `AppServiceProvider` for custom redirects.

### Registration Customization

Modify `app/Actions/Fortify/CreateNewUser.php` to customize user creation logic, validation rules, and additional fields.

### Rate Limiting

Configure via `fortify.limiters.login` in config. Default configuration throttles by username + IP combination.

## Key Endpoints

| Feature                | Method   | Endpoint                                    |
|------------------------|----------|---------------------------------------------|
| Login                  | POST     | `/login`                                    |
| Logout                 | POST     | `/logout`                                   |
| Register               | POST     | `/register`                                 |
| Password Reset Request | POST     | `/forgot-password`                          |
| Password Reset         | POST     | `/reset-password`                           |
| Email Verify Notice    | GET      | `/email/verify`                             |
| Resend Verification    | POST     | `/email/verification-notification`          |
| Password Confirm       | POST     | `/user/confirm-password`                    |
| Enable 2FA             | POST     | `/user/two-factor-authentication`           |
| Confirm 2FA            | POST     | `/user/confirmed-two-factor-authentication` |
| 2FA Challenge          | POST     | `/two-factor-challenge`                     |
| Get QR Code            | GET      | `/user/two-factor-qr-code`                  |
| Recovery Codes         | GET/POST | `/user/two-factor-recovery-codes`           |