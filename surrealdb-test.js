const { exec } = require('child_process');
describe('SurrealDB Service Tests', () => {
  it('should start SurrealDB with RockDB by default', (done) => {
    exec('docker-compose up -d surrealdb', (err, stdout, stderr) => {
      if (err) {
        done.fail(`Error starting SurrealDB: ${stderr}`);
      } else {
        exec('docker logs surrealdb', (logErr, logs) => {
          if (logErr) {
            done.fail(`Error fetching logs: ${logErr}`);
          } else {
            if (logs.includes('rockdb')) {
              done();
            } else {
              done.fail('SurrealDB did not start with RockDB backend.');
            }
          }
        });
      }
    });
  });

  it('should start SurrealDB with TIKV when specified', (done) => {
    process.env.SURREALDB_BACKEND = 'tikv';
    exec('docker-compose up -d surrealdb', (err, stdout, stderr) => {
      if (err) {
        done.fail(`Error starting SurrealDB: ${stderr}`);
      } else {
        exec('docker logs surrealdb', (logErr, logs) => {
          if (logErr) {
            done.fail(`Error fetching logs: ${logErr}`);
          } else {
            if (logs.includes('tikv')) {
              done();
            } else {
              done.fail('SurrealDB did not start with TIKV backend.');
            }
          }
        });
      }
    });
  });
});