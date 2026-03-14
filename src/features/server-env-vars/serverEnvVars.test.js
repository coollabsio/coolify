// serverEnvVars.test.js

const loadServerEnvVars = require('./serverEnvVars');
const fs = require('fs');
const path = require('path');

jest.mock('fs');

describe('loadServerEnvVars', () => {
  it('should load environment variables from .env.production file', () => {
    const mockEnvData = 'VAR1=value1\nVAR2=value2';
    fs.existsSync.mockReturnValue(true);
    fs.readFileSync.mockReturnValue(mockEnvData);

    const result = loadServerEnvVars();
    expect(result).toEqual({ VAR1: 'value1', VAR2: 'value2' });
  });

  it('should throw an error if .env.production file does not exist', () => {
    fs.existsSync.mockReturnValue(false);

    expect(() => loadServerEnvVars()).toThrow('.env.production file not found!');
  });
});
