import unittest
from io import StringIO
import sys

class TestServerDeployment(unittest.TestCase):
    def setUp(self):
        # Set up server environment variables for testing
        self.server_envs = {
            "server1": {"ENV_VAR_SERVER": "Server1_Env_Variable"},
            "server2": {"ENV_VAR_SERVER": "Server2_Env_Variable"}
        }

    def test_deploy_server1(self):
        # Capture the output
        captured_output = StringIO()
        sys.stdout = captured_output

        deployment = ServerDeployment("server1", self.server_envs)
        deployment.deploy()

        # Check if the output contains the correct environment variable for server1
        self.assertIn("Log: Server ID = server1, Env: Server1_Env_Variable", captured_output.getvalue())
        
    def test_deploy_server2(self):
        # Capture the output
        captured_output = StringIO()
        sys.stdout = captured_output

        deployment = ServerDeployment("server2", self.server_envs)
        deployment.deploy()

        # Check if the output contains the correct environment variable for server2
        self.assertIn("Log: Server ID = server2, Env: Server2_Env_Variable", captured_output.getvalue())

if __name__ == '__main__':
    unittest.main()