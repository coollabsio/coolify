# Contributing to Coolify

## How to contribute

- This is a temporary guide until the v5 docker architecture & development setup is ready.

1. Fork the repository
2. Create a new branch or create a branch with the same name as one of the upstream branches
3. Sync your fork with the upstream branches
4. Clone and checkout the code locally
5. Copy `.env.example` to a new `.env` file (no changes should be needed)
6. As the v5 docker architecture is not ready yet, you need to install PHP, composer and bun locally
    6.1. Install PHP, composer and the laravel installer [https://laravel.com/docs/12.x/installation#installing-php](https://laravel.com/docs/12.x/installation#installing-php)
    6.2. Install bun [https://bun.com/docs/installation](https://bun.com/docs/installation)
7. Run `composer install` to install PHP dependencies
8. Run `bun install` to install JS dependencies
9. Run `composer run dev` to create an sqlite database, generate an APP_KEY, migrate the database and run the development server
10. Access the application at `http://localhost:8000`
