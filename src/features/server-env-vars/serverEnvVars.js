// serverEnvVars.js

// Load environment variables from the system
const fs = require('fs');
const path = require('path');

// Function to load server level environment variables
function loadServerEnvVars() {
  const envPath = path.resolve(__dirname, '../../.env.production');
  if (!fs.existsSync(envPath)) {
    throw new Error('.env.production file not found!');
  }
  const envVars = fs.readFileSync(envPath, 'utf-8');
  const parsedVars = envVars.split('\n').reduce((acc, line) => {
    const [key, value] = line.split('=');
    if (key && value) acc[key.trim()] = value.trim();
    return acc;
  }, {});
  return parsedVars;
}

// Export the function to be used in other modules
module.exports = loadServerEnvVars;
