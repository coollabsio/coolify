---
type: added
commit: a5b4e1d32e
---
A separate notification sender in the instance email settings

- Team notifications through the instance email settings, the S3 connection error alert and the channel disabled alert use it
- Account emails keep the default sender
- The `emails` artisan command no longer exists
