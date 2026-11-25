---
type: added
---
A `Rescan frequency` setting for image scanning on the `Vulnerabilities` page of a server

- Rescans run every day, every 3, 7, 14, or 30 days, or never. The API field is `image_rescan_days`
- A result needs a check one day after its next rescan is due, or after 2 days for daily rescans and Never
