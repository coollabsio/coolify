# Contributing to Coolify
We’re happy that you’re interested in contributing to Coolify!

There are many ways to help:
- Answer questions in GitHub Discussions or Discord
- Report reproducible bugs
- Submit pull requests to fix issues
- Add new one-click services
- Improve documentation

Coolify is a PaaS used by 400,000+ people worldwide and maintained by two active maintainers. Contributions are welcome — but **alignment matters more than quantity**.

This guide explains **what kind of contributions are likely to be accepted** and how to submit them properly. Following it saves time for both you and the maintainers.

> [!IMPORTANT]
> These guidelines may feel stricter than in many open-source projects. That is intentional.
> Clear structure and boundaries prevent maintainer burnout and keep the project sustainable long-term.


## High-Level Expectations
- Coolify has a clear product direction.
- Ownership and decisions are centralized.
- Review capacity is limited.
- Not every contribution will be accepted — even if technically correct.

This is normal for a two-maintainer project.


## State of the Project
Coolify is currently at v4. While v4 is stable, it has some limitations, including:
- Limited scaling support
- A more complex user experience
- Other smaller issues that need refinement

These limitations will be addressed in Coolify v5, which is in the planning stage. Because of this, major features, architectural changes, or significant UI changes will not be accepted for v4 at this stage.

We welcome contributions that help stabilize v4 for a bug free experience.


## What Makes a Strong Contribution
The following types of contributions are most likely to be accepted:

#### Code Quality and Testing
All contributions must adhere to the highest standards of code quality and testing:

If your change is small and obvious (typo fix, small bug, minor docs update), you may open a pull request directly.


If you are fixing a bug in `file.yaml`, do not:
- Reformat unrelated files
- Refactor unrelated code
- Fix style issues elsewhere
- Combine multiple unrelated changes

Even “improvements” increase review complexity.

**One pull request = one logical change.**

If you want to refactor or clean up code, discuss it first and submit it separately.


## Discussion Is Required for Larger Changes
For anything beyond a small fix, you must discuss it before opening a pull request.

This includes:
- New features
- UI/UX changes
- Changes to default behavior
- Refactors or cleanup work
- Performance rewrites
- Architectural changes
- Changes touching many files

Discussion happens in GitHub Discussions: https://github.com/coollabsio/coolify/discussions/categories/general

Pull requests introducing major changes without prior discussion will be closed without review.

This ensures alignment before significant work is done.


## What This Project Is Not
To set clear expectations:
- Coolify is not optimized for first-time open-source contributors
- We do not provide beginner-focused mentorship issues
- Large unsolicited changes are unlikely to be accepted
- Broad refactors or style rewrites are not helpful
- Low-effort AI-generated pull requests will be closed

AI usage is allowed. However, contributors must fully understand what their changes do and why.

Clear expectations help everyone use their time effectively.


# Ways to Contribute
## 1. Support Contributions
We use Discord for most support requests and GitHub Discussions for help.

### Requesting Support
If you need help:
- Provide complete and detailed information
- Include logs, screenshots, and steps to reproduce
- Be respectful — support is voluntary

Do not ping people for attention. They respond when available.

### Providing Support
If you help others:
- Verify your information before sharing
- Be patient and respectful
- Remember that not everyone has the same experience level


## 2. Bug Report Contributions
Create a GitHub issue **only** if:
- The bug is reproducible
- You have confirmed no existing issue already covers it

For questions or general help, use GitHub Discussions or the Discord support channel.

Bug reports must include:
- Clear reproduction steps
- Expected result
- Actual result

Incomplete reports and reports generated using AI may be closed.


## 3. Code Contributions
Maintainers may close pull requests at their discretion, without explanation.

### Issue Requirement
Every pull request should reference and close an Issue or Discussion.

If none exists, create one first.

Pull requests without linked issue or discussions may not be reviewed and can be closed at any time.


## Commit Message Format
All commits must start with an action and category:
- `fix(ui):` — UI-related fixes
- `feat(api):` — API-related changes
- `feat(service):` — One-click service changes

Examples:
- `fix(api): version endpoint returns wrong data`
- `feat(service): add supabase`

Use the commit description only for concise context.

Walls of text listing every change in description will be rejected.


## Pull Request Title Format
Pull request titles follow the same format:
- `fix(ui):`
- `feat(api):`
- `feat(service):`

Examples:
- `fix(api): version endpoint returns wrong data`
- `feat(service): add supabase`


## AI Usage Disclosure
If AI tools were used at any stage, mention it in the pull request description.

AI is allowed.

However:
- You must understand every change
- You must verify correctness
- You must ensure it follows project patterns

AI-generated pull requests without clear understanding will be closed.


## Test Before Submitting
Before submitting a pull request:
- Manually test your changes thoroughly
- Verify they work in a clean environment
- Provide detailed testing steps in the PR description

If maintainers cannot reproduce working behavior, the PR will be closed without further review.


## Submitting a Pull Request
- GitHub will auto-populate the PR template
- The contributor agreement in PR description must remain intact
- Pull requests without the contributor agreement will be closed
- All pull requests must target the `next` branch
- PRs targeting other branches will be closed without review


## FAQ
**Q: Should I ask before fixing a typo or a small bug?**  
A: No, small, obvious fixes like typos or narrowly-scoped bug fixes can be submitted as a PR directly.

**Q: I have an idea for a new feature.**  
A: Awesome! Discuss it first in GitHub Discussions or Discord. **Do not** open a PR for new features without prior alignment.

**Q: My PR was closed without detailed feedback.**  
A: This usually means it didn’t align with the project’s direction, required more review bandwidth than available, or targeted major changes not allowed in v4. 

**Q: Can I work on an open issue?**  
A: Comment on the issue first to confirm it’s still relevant and that no one else is actively working on it. For anything beyond a small fix, discuss your approach before implementing.

**Q: I noticed code that could be cleaned up while working on my change.**  
A: Focus only on your stated goal. Cleanups or refactors should be submitted as separate PRs after discussion.

**Q: Can I use AI to help with my PR?**  
A: Yes, AI-assisted contributions are allowed. But you must fully understand and verify the changes. PRs that appear to be generated by AI without context understanding will be closed.

**Q: My PR was closed without review. Can I submit a new one?**  
A: Yes, but keep in mind a PR closure is feedback, not a rejection of your effort. It usually means the PR didn’t match the project goals or guidelines. Address these issues first — repeating the same approach may hurt your standing with maintainers.


# Development Guides
## Local Development
To build and run Coolify locally, see: [Development](./DEVELOPMENT.md)

### macOS Development with Lima
Mac users can use [Lima](https://lima-vm.io/) to run a lightweight Linux virtual machine for local Coolify development. This is useful if you prefer a Linux-based Docker environment on macOS.

After creating and starting a Lima VM, run the normal local development commands from inside the VM as described in [Development](./DEVELOPMENT.md).

## Adding a New Service
To add a new one-click service, follow: https://coolify.io/docs/get-started/contribute/service

## Contributing to Documentation
To contribute to documentation, see: https://coolify.io/docs/get-started/contribute/documentation
