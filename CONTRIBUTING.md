# Contributing

> "First, thanks for considering contributing to my project. It really means a lot!" - [@andrasbacsai](https://github.com/andrasbacsai)

You can ask for guidance anytime on our [Discord server](https://coollabs.io/discord) in the `#contribute` channel.


## Code Contribution

## 1. Setup your development environment 

Follow the steps below for your operating system:

### Windows

1. Install Docker Desktop (or similar):
   - Download and install [Docker Desktop for Windows](https://docs.docker.com/desktop/install/windows-install/)

2. Install Spin:
   - Follow the instructions to install Spin on Windows from the [Spin documentation](https://serversideup.net/open-source/spin/docs/installation/install-windows)

### MacOS

1. Install Orbstack (recommended), Docker Desktop (or similar):
   - Orbstack (faster, lighter, better alternative to Docker Desktop)
     - Download and install [Orbstack](https://docs.orbstack.dev/quick-start#installation)
   - Docker Desktop:
     - Download and install [Docker Desktop for Mac](https://docs.docker.com/desktop/install/mac-install/)

2. Install Spin:
   - Follow the instructions to install Spin on MacOS from the [Spin documentation](https://serversideup.net/open-source/spin/docs/installation/install-macos/#download-and-install-spin)

### Linux

1. Install Docker Engine (recommended), Docker Desktop (or similar):
   - Docker Engine (use this, as there is no VM overhead):
     - Follow the official [Docker Engine installation guide](https://docs.docker.com/engine/install/) for your Linux distribution
   - Docker Desktop:
     - If you want a GUI, you can use [Docker Desktop for Linux](https://docs.docker.com/desktop/install/linux-install/)

2. Install Spin:
   - Follow the instructions to install Spin on Linux from the [Spin documentation](https://serversideup.net/open-source/spin/docs/installation/install-linux#configure-docker-permissions)


## 2. Verify installation

After installing Docker (or Orbstack) and Spin, verify the installation:

1. Open a terminal or command prompt
2. Run the following commands:
   ```bash
   docker --version
   spin --version
   ```
   You should see version information for both Docker and Spin.


## 3. Fork/Clone the Coolify repository and setup your local repository

1. Fork/clone the [Coolify](https://github.com/coollabsio/coolify) repository to your GitHub account.

2. Install a code editor on your machine (below are some popular choices, choose one):

   - Visual Studio Code (recommended):
     - Windows/macOS/Linux: Download and install from [https://code.visualstudio.com/download](https://code.visualstudio.com/download)

   - Cursor (recommended but not free if you really want to use it):
     - Windows/macOS/Linux: Download and install from [https://www.cursor.com/](https://www.cursor.com/)

   - Zed (very fast code editor):
     - macOS/Linux: Download and install from [https://zed.dev/download](https://zed.dev/download)
     - Windows: Not available yet

3. Clone the Coolify Repository to your local machine
   - Use `git clone` in the command line
   - Use GitHub Desktop (recommended):
     - Download and install from [https://desktop.github.com/](https://desktop.github.com/)

4. Open the cloned Coolify Repository in your chosen code editor.


## 4. Set up Environment Variables

1. Locate the `.env.development.example` file in the root directory of your local Coolify repository.

2. Duplicate the `.env.development.example` file and rename the copy to `.env`.

3. Open the new `.env` file and review its contents. Adjust any environment variables as needed for your development setup.

4. If you encounter errors during database migrations, update the database connection settings in your `.env` file. Use the IP address or hostname of your PostgreSQL database container. You can find this information by running `docker ps` after executing `spin up`.

5. Save the changes to your `.env` file.


## 5. Start Coolify

1. Open a terminal in the Coolify directory.

2. Run the following command:
   ```
   spin up
   ```
   Note: You may see some errors, but don't worry; this is expected.

3. If you encounter permission errors, especially on macOS, use:
   ```
   sudo spin up
   ```


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


## 7. Contributing a New Service

To add a new service to Coolify, please refer to our documentation:
[Adding a New Service](https://coolify.io/docs/knowledge-base/add-a-service)
