import os
import json

class ServerDeployment:
    def __init__(self, server_id, server_envs):
        self.server_id = server_id
        self.server_envs = server_envs

    def deploy(self):
        # Get the environment variables for this server
        server_env = self.server_envs.get(self.server_id, {})
        for key, value in server_env.items():
            os.environ[key] = value  # Set environment variables for the server
        
        # Now deploy the application (placeholder for actual deployment logic)
        print(f"Deploying on {self.server_id} with environment variables: {server_env}")

        # Simulate the application log showing which server the issue occurred on
        self.log_server_info()

    def log_server_info(self):
        # Here, the server environment variable is printed in the logs
        print(f"Log: Server ID = {self.server_id}, Env: {os.getenv('ENV_VAR_SERVER')}")


# Server-specific environment variables
server_envs = {
    "server1": {
        "ENV_VAR_SERVER": "Server1_Env_Variable"
    },
    "server2": {
        "ENV_VAR_SERVER": "Server2_Env_Variable"
    }
}

# Example deployment on server1
deployment1 = ServerDeployment("server1", server_envs)
deployment1.deploy()

# Example deployment on server2
deployment2 = ServerDeployment("server2", server_envs)
deployment2.deploy()