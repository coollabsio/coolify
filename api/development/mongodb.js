const mongoose = require('mongoose')
const { MongoMemoryServer } = require('mongodb-memory-server-core')

const mongoServer = new MongoMemoryServer({
    instance: {
        port: 27017,
        dbName: 'coolify',
        storageEngine: 'wiredTiger'
    },
    binary: {
        version: '4.4.3',

    }
});

mongoose.Promise = Promise;
mongoServer.getUri().then((mongoUri) => {
  const mongooseOpts = {
    useNewUrlParser: true,
    useUnifiedTopology: true
  };

  mongoose.connect(mongoUri, mongooseOpts);

  mongoose.connection.on('error', (e) => {
    if (e.message.code === 'ETIMEDOUT') {
      console.log(e);
      mongoose.connect(mongoUri, mongooseOpts);
    }
    console.log(e);
  });

  mongoose.connection.once('open', () => {
    console.log(`Started in-memory mongodb ${mongoUri}`);
  });
});