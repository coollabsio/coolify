# Contributing

> "First, thanks for considering to contribute to my project. It really means a lot!" - [@andrasbacsai](https://github.com/andrasbacsai)

You can ask for guidance anytime on our [Discord server](https://coollabs.io/discord) in the `#contribute` channel.

## Code Contribution

### 1) Setup your development environment 

Follow the steps below for your operating system:

#### Windows

1. Install Docker Desktop (or similar):
   - Download and install [Docker Desktop for Windows](https://docs.docker.com/desktop/install/windows-install/)
   - Follow the installation instructions provided on the Docker website

2. Install Spin:
   - Follow the instructions to install Spin on Windows from the [Spin documentation](https://serversideup.net/open-source/spin/docs/installation/install-windows)

#### MacOS

1. Install Orbstack or Docker Desktop (or similar):
   - Orbstack (faster, lighter, better alternative to Docker Desktop)
     - Download and install [Orbstack](https://docs.orbstack.dev/quick-start#installation)
   - Docker Desktop:
     - Download and install [Docker Desktop for Mac](https://docs.docker.com/desktop/install/mac-install/)

2. Install Spin:
   - Follow the instructions to install Spin on MacOS from the [Spin documentation](https://serversideup.net/open-source/spin/docs/installation/install-macos)

#### Linux

1. Install Docker Engine or Docker Desktop (or similar):
   - Docker Engine (recommended):
     - Follow the official [Docker Engine installation guide](https://docs.docker.com/engine/install/) for your Linux distribution
   - Docker Desktop:
     - If you want a GUI, you can use Docker Desktop [Docker Desktop for Linux](https://docs.docker.com/desktop/install/linux-install/)

2. Install Spin:
   Follow the instructions to install Spin on Linux from the [Spin documentation](https://serversideup.net/open-source/spin/docs/installation/install-linux)

### 2) Verify Installation

After installing Docker (or Orbstack) and Spin, verify the installation:

1. Open a terminal or command prompt
2. Run the following commands:
   ```bash
   docker --version
   spin --version
   ```
   You should see version information for both Docker and Spin.

### 3) Fork/Clone the Coolify Repository and Setup your Development Environment

1. Fork/clone the [Coolify](https://github.com/coollabsio/coolify) repository to your GitHub account.

2. Install a code editor on your machine (choose one):

   - Visual Studio Code:
     - Windows/macOS/Linux: Download and install from [https://code.visualstudio.com/](https://code.visualstudio.com/)

   - Cursor (recommended):
     - Windows/macOS/Linux: Download and install from [https://cursor.sh/](https://cursor.sh/)

   - Zed (very fast code editor):
     - macOS/Linux: Download and install from [https://zed.dev/](https://zed.dev/)
     - Windows: Not available yet

3. Clone the Coolify Repository to your local machine
   - Use `git clone` in the commandline
   - Use GitHub Desktop (recommended):
     - Download and install from [https://desktop.github.com/download/](https://desktop.github.com/download/)

4. Open the cloned Coolify Repository in your choosen code editor.

### 4) Set up Environment Variables

1. Copy the `.env.development.example` file to your `.env` file.

2. Set the database connection:
   - For macOS users with Orbstack, update the `DB_HOST` variable to `postgres.coolify.orb.local`:
     ```
     DB_HOST=postgres.coolify.orb.local
     ```
   - For other systems, you may need to use the appropriate IP address or hostname of your PostgreSQL database.

3. Review and adjust other environment variables as needed for your development setup.

### 5) Start & Setup Coolify

1. Open a terminal in the Coolify directory.

2. Run the following command:
   ```
   spin up
   ```
   Note: You may see some errors, but don't worry; this is expected.

3. If you encounter permission errors, especially on MacOS, use:
   ```
   sudo spin up
   ```

### 6) Start Development

1. Access your Coolify instance:
   - URL: `http://localhost:8000`
   - Login: `test@example.com`
   - Password: `password`

2. Additional development tools:
   - Laravel Horizon (scheduler): `http://localhost:8000/horizon`
     Note: Only accessible when logged in as root user
   - Mailpit (email catcher): `http://localhost:8025`

### 7) Contributing a New Service

To add a new service to Coolify, please refer to our documentation:
[Adding a New Service](https://coolify.io/docs/knowledge-base/add-a-service)
