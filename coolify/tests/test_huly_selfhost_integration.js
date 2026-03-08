const { exec } = require('child_process');

describe('Huly Self-host Integration', () => {
  it('should run the Huly Self-host container', done => {
    exec('docker-compose -f coolify/templates/huly_selfhost/docker-compose.yml up -d', (err, stdout, stderr) => {
      if (err) {
        done(err);
      }
      expect(stdout).toContain('Starting huly_selfhost');
      done();
    });
  });
});