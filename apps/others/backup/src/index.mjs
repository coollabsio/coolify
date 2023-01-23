import * as dotenv from 'dotenv';
dotenv.config()

import 'zx/globals';
import cuid from 'cuid';
import { S3, PutObjectCommand } from "@aws-sdk/client-s3";
import fs from 'fs';

const isDev = process.env.NODE_ENV === 'development'
$.verbose = !!isDev

if (!process.env.CONTAINERS_TO_BACKUP && !isDev) {
  console.log(chalk.red(`No containers to backup!`))
  process.exit(1)
}
const mysqlGzipLocal = 'clb6c9ue4000a8lputdd5g1cl:database:mysql:gzip:local';
const mysqlRawLocal = 'clb6c9ue4000a8lputdd5g1cl:database:mysql:raw:local';
const postgresqlGzipLocal = 'clb6c15yi00008lpuezop7cy0:database:postgresql:gzip:local';
const postgresqlRawLocal = 'clb6c15yi00008lpuezop7cy0:database:postgresql:raw:local';

const minio = 'clb6c9ue4000a8lputdd5g1cl:database:mysql:gzip:minio|http|min.arm.coolify.io|backups|<access_key>|<secret_key>';
const digitalOcean = 'clb6c9ue4000a8lputdd5g1cl:database:mysql:gzip:do|https|fra1.digitaloceanspaces.com|backups|<access_key>|<secret_key>';

const devContainers = [mysqlGzipLocal, mysqlRawLocal, postgresqlGzipLocal, postgresqlRawLocal]

const containers = isDev
  ? devContainers
  : process.env.CONTAINERS_TO_BACKUP.split(',')

const backup = async (container) => {
  const id = cuid()
  const [name, backupType, type, zipped, storage] = container.split(':')
  const directory = `backups`;
  const filename = zipped === 'raw'
    ? `${name}-${type}-${backupType}-${new Date().getTime()}.sql`
    : `${name}-${type}-${backupType}-${new Date().getTime()}.tgz`
  const backup = `${directory}/${filename}`;

  try {
    await $`docker inspect ${name.split(' ')[0]}`.quiet()
    if (backupType === 'database') {
      if (type === 'mysql') {
        console.log(chalk.blue(`Backing up ${name}:${type}...`))
        const { stdout: rootPassword } = await $`docker exec ${name} printenv MYSQL_ROOT_PASSWORD`.quiet()
        if (zipped === 'raw') {
          await $`docker exec ${name} sh -c "exec mysqldump --all-databases -uroot -p${rootPassword.trim()}" > ${backup}`
        } else if (zipped === 'gzip') {
          await $`docker exec ${name} sh -c "exec mysqldump --all-databases -uroot -p${rootPassword.trim()}" | gzip > ${backup}`
        }
      }
      if (type === 'postgresql') {
        console.log(chalk.blue(`Backing up ${name}:${type}...`))
        const { stdout: userPassword } = await $`docker exec ${name} printenv POSTGRES_PASSWORD`
        const { stdout: user } = await $`docker exec ${name} printenv POSTGRES_USER`
        if (zipped === 'raw') {
          await $`docker exec ${name} sh -c "exec pg_dumpall -c -U${user.trim()}" -W${userPassword.trim()}> ${backup}`
        } else if (zipped === 'gzip') {
          await $`docker exec ${name} sh -c "exec pg_dumpall -c -U${user.trim()}" -W${userPassword.trim()} | gzip > ${backup}`
        }
      }
      const [storageType, ...storageArgs] = storage.split('|')
      if (storageType !== 'local') {
        let s3Protocol, s3Url, s3Bucket, s3Key, s3Secret = null
        if (storageArgs.length > 0) {
          [s3Protocol, s3Url, s3Bucket, s3Key, s3Secret] = storageArgs
        }
        if (storageType === 'minio') {
          if (!s3Protocol || !s3Url || !s3Bucket || !s3Key || !s3Secret) {
            console.log(chalk.red(`Invalid storage arguments for ${name}:${type}!`))
            return
          }
          await $`mc alias set ${id} ${s3Protocol}://${s3Url} ${s3Key} ${s3Secret}`
          await $`mc stat ${id}`
          await $`mc cp ${backup} ${id}/${s3Bucket}`
          await $`rm ${backup}`
          await $`mc alias rm ${id}`
        } else if (storageType === 'do') {
          if (!s3Protocol || !s3Url || !s3Bucket || !s3Key || !s3Secret) {
            console.log(chalk.red(`Invalid storage arguments for ${name}:${type}!`))
            return
          }
          console.log({ s3Protocol, s3Url, s3Bucket, s3Key, s3Secret })
          console.log(chalk.blue(`Uploading ${name}:${type} to DigitalOcean Spaces...`))
          const readstream = fs.createReadStream(backup)
          const bucketParams = {
            Bucket: s3Bucket,
            Key: filename,
            Body: readstream
          };
          const s3Client = new S3({
            forcePathStyle: false,
            endpoint: `${s3Protocol}://${s3Url}`,
            region: "us-east-1",
            credentials: {
              accessKeyId: s3Key,
              secretAccessKey: s3Secret
            },
          });
          try {
            const data = await s3Client.send(new PutObjectCommand(bucketParams));
            console.log(chalk.green("Successfully uploaded backup: " +
              bucketParams.Bucket +
              "/" +
              bucketParams.Key
            )
            );
            return data;
          } catch (err) {
            console.log("Error", err);
          }
        }
      }
    }

    console.log(chalk.green(`Backup of ${name}:${type} complete!`))
  } catch (error) {
    console.log(chalk.red(`Backup of ${name}:${type} failed!`))
    console.log(chalk.red(error))
  }
}
const promises = []
for (const container of containers) {
  // await backup(container);
  promises.push(backup(container))
}
await Promise.all(promises)