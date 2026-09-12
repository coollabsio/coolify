# Fix Node address rendering

- [x] Add a regression assertion for the rendered SSH address.
- [x] Fix the Blade expression so `@` does not escape the IP expression.
- [x] Run focused tests and verify the compiled view.
- [x] Search related GitHub issues and discussions.

## Review

- Rendered the complete SSH address with one Blade expression.
- Added assertions for the resolved address and against leaked Blade syntax.
- Eight focused tests passed with 30 assertions. Blade compilation passed.
- No related GitHub issues or discussions were found.
