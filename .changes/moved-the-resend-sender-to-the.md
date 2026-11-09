---
type: changed
breaking: true
commit: f7867923b7
---
Moved the Resend sender to the new `resend_from_address` and `resend_from_name` fields, API clients must use the new fields

- A migration copies the current values
