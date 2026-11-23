---
type: added
---
Deployment of static sites from an uploaded `.tar`, `.tar.gz`, or `.tgz` archive, with the `Static Site (Upload)` resource type and the API

- A new upload on the `General` page of the site, or through `POST /api/v1/applications/{uuid}/archive`, builds and deploys a new version
- Coolify keeps no copy of the archive. `Redeploy`, `Restart`, and `Rollback` run the image of the version on the server without a build, and ask for a new upload when the image is gone
- `Single-page application` and `Custom Nginx configuration` apply at the next upload
- The pages and settings that do not apply to these sites, such as `Environment Variables`, `Persistent Storage`, and `Deployment commands`, are hidden
- When the instance admin selects an upload storage in `Settings` > `Advanced` > `Storage` > `Uploads`, the browser sends the archive straight to that S3 bucket, so it does not use the disk of the Coolify instance. The API has `POST /api/v1/applications/archive/uploads/s3` for the same
- A team can upload at most 5 GiB of archives to the Coolify instance in one hour, and keeps its 3 newest uploads that are not deployed yet
