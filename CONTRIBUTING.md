# Contributing

> "First, thanks for considering contributing to my project. It really means a lot!" - [@andrasbacsai](https://github.com/andrasbacsai)

You can ask for guidance anytime on our [Discord server](https://coollabs.io/discord) in the `#contribute` channel.


## Code Contribution

## 1. Setup your development environment 

Follow the steps below for your operating system:

### Windows

1. Install `docker-ce`, Docker Desktop (or similar):
   - Docker CE (recommended):
     - Install Windows Subsystem for Linux v2 (WSL2) by following this guide: [Install WSL](https://learn.microsoft.com/en-us/windows/wsl/install)
     - After installing WSL2, install Docker CE for your Linux distribution by following this guide: [Install Docker Engine](https://docs.docker.com/engine/install/)
     - Make sure to choose the appropriate Linux distribution (e.g., Ubuntu) when following the Docker installation guide
   - Install Docker Desktop (easier):
     - Download and install [Docker Desktop for Windows](https://docs.docker.com/desktop/install/windows-install/)
     - Ensure WSL2 backend is enabled in Docker Desktop settings

2. Install Spin:
   - Follow the instructions to install Spin on Windows from the [Spin documentation](https://serversideup.net/open-source/spin/docs/installation/install-windows#download-and-install-spin-into-wsl2)

### MacOS

1. Install Orbstack, Docker Desktop (or similar):
   - Orbstack (recommended, as it is a faster and lighter alternative to Docker Desktop):
     - Download and install [Orbstack](https://docs.orbstack.dev/quick-start#installation)
   - Docker Desktop:
     - Download and install [Docker Desktop for Mac](https://docs.docker.com/desktop/install/mac-install/)

2. Install Spin:
   - Follow the instructions to install Spin on MacOS from the [Spin documentation](https://serversideup.net/open-source/spin/docs/installation/install-macos/#download-and-install-spin)

### Linux

1. Install Docker Engine, Docker Desktop (or similar):
   - Docker Engine (recommended, as there is no VM overhead):
     - Follow the official [Docker Engine installation guide](https://docs.docker.com/engine/install/) for your Linux distribution
   - Docker Desktop:
     - If you want a GUI, you can use [Docker Desktop for Linux](https://docs.docker.com/desktop/install/linux-install/)

2. Install Spin:
   - Follow the instructions to install Spin on Linux from the [Spin documentation](https://serversideup.net/open-source/spin/docs/installation/install-linux#configure-docker-permissions)


## 2. Verify installation (optional)

After installing Docker (or Orbstack) and Spin, verify the installation:

1. Open a terminal or command prompt
2. Run the following commands:
   ```bash
   docker --version
   spin --version
   ```
   You should see version information for both Docker and Spin.


## 3. Fork the Coolify repository and setup your local repository

1. Fork the [Coolify](https://github.com/coollabsio/coolify) repository to your GitHub account.

2. Install a code editor on your machine (below are some popular choices, choose one):

   - Visual Studio Code (recommended free):
     - Windows/macOS/Linux: Download and install from [https://code.visualstudio.com/download](https://code.visualstudio.com/download)

   - Cursor (recommended but paid for getting the full benefits):
     - Windows/macOS/Linux: Download and install from [https://www.cursor.com/](https://www.cursor.com/)

   - Zed (very fast code editor):
     - macOS/Linux: Download and install from [https://zed.dev/download](https://zed.dev/download)
     - Windows: Not available yet

3. Clone the Coolify Repository from your fork to your local machine
   - Use `git clone` in the command line
   - Use GitHub Desktop (recommended):
     - Download and install from [https://desktop.github.com/](https://desktop.github.com/)
     - Open GitHub Desktop and login with your GitHub account
     - Click on `File` -> `Clone Repository` select `github.com` as the repository location, then select your forked Coolify repository, choose the local path and then click `Clone`

4. Open the cloned Coolify Repository in your chosen code editor.


## 4. Set up Environment Variables

1. In the Code Editor, locate the `.env.development.example` file in the root directory of your local Coolify repository.

2. Duplicate the `.env.development.example` file and rename the copy to `.env`.

3. Open the new `.env` file and review its contents. Adjust any environment variables as needed for your development setup.

4. If you encounter errors during database migrations, update the database connection settings in your `.env` file. Use the IP address or hostname of your PostgreSQL database container. You can find this information by running `docker ps` after executing `spin up`.

5. Save the changes to your `.env` file.


## 5. Start Coolify

1. Open a terminal in the local Coolify directory.

2. Run the following command in the terminal (leave that terminal open):
   ```
   spin up
   ```
   Note: You may see some errors, but don't worry; this is expected.

3. If you encounter permission errors, especially on macOS, use:
   ```
   sudo spin up
   ```

Note: If you change environment variables afterwards or anything seems broken, press Ctrl + C to stop the process and run `spin up` again.


## 6. Start Development

1. Access your Coolify instance:
   - URL: `http://localhost:8000`
   - Login: `test@example.com`
   - Password: `password`

2. Additional development tools:
   - Laravel Horizon (scheduler): `http://localhost:8000/horizon`
     Note: Only accessible when logged in as root user
   - Mailpit (email catcher): `http://localhost:8025`
   - Telescope (debugging tool): `http://localhost:8000/telescope` 
     Note: Disabled by default (so the database is not overloaded), enable by adding the following environment variable to your `.env` file:
     ```env
     TELESCOPE_ENABLED=true
     ```


## 7. Development Notes

When working on Coolify, keep the following in mind:

1. **Database Migrations**: After switching branches or making changes to the database structure, always run migrations:
   ```bash
   docker exec -it coolify php artisan migrate
   ```

2. **Resetting Development Setup**: To reset your development setup to a clean database with default values:
   ```bash
   docker exec -it coolify php artisan migrate:fresh --seed
   ```

3. **Troubleshooting**: If you encounter unexpected behavior, ensure your database is up-to-date with the latest migrations and if possible reset the development setup to eliminate any envrionement specific issues.

Remember, forgetting to migrate the database can cause problems, so make it a habit to run migrations after pulling changes or switching branches.


## 8. Contributing a New Service

To add a new service to Coolify, please refer to our documentation:
[Adding a New Service](https://coolify.io/docs/knowledge-base/add-a-service)


## 9. Create a Pull Request

1. After making changes or adding a new service:
   - Commit your changes to your forked repository.
   - Push the changes to your GitHub account.

2. Creating the Pull Request (PR):
   - Navigate to the main Coolify repository on GitHub.
   - Click the "Pull requests" tab.
   - Click the green "New pull request" button.
   - Choose your fork and branch as the compare branch.
   - Click "Create pull request".

3. Filling out the PR details:
   - Give your PR a descriptive title.
   - In the description, explain the changes you've made.
   - Reference any related issues by using keywords like "Fixes #123" or "Closes #456".

4. Important note:
   Always set the base branch for your PR to the `next` branch of the Coolify repository, not the `main` branch.

5. Submit your PR:
   - Review your changes one last time.
   - Click "Create pull request" to submit.

After submission, maintainers will review your PR and may request changes or provide feedback.
