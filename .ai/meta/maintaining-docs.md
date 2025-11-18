# Maintaining AI Documentation

Guidelines for creating and maintaining AI documentation to ensure consistency and effectiveness across all AI tools (Claude Code, Cursor IDE, etc.).

## Documentation Structure

All AI documentation lives in the `.ai/` directory with the following structure:

```
.ai/
├── README.md                    # Navigation hub
├── core/                        # Core project information
├── development/                 # Development practices
├── patterns/                    # Code patterns and best practices
└── meta/                        # Documentation maintenance guides
```

> **Note**: `CLAUDE.md` is in the repository root, not in the `.ai/` directory.

## Required File Structure

When creating new documentation files:

```markdown
# Title

Brief description of what this document covers.

## Section 1

- **Main Points in Bold**
  - Sub-points with details
  - Examples and explanations

## Section 2

### Subsection

Content with code examples:

```language
// ✅ DO: Show good examples
const goodExample = true;

// ❌ DON'T: Show anti-patterns
const badExample = false;
```
```

## File References

- Use relative paths: `See [technology-stack.md](../core/technology-stack.md)`
- For code references: `` `app/Models/Application.php` ``
- Keep links working across different tools

## Content Guidelines

### DO:
- Start with high-level overview
- Include specific, actionable requirements
- Show examples of correct implementation
- Reference existing code when possible
- Keep documentation DRY by cross-referencing
- Use bullet points for clarity
- Include both DO and DON'T examples

### DON'T:
- Create theoretical examples when real code exists
- Duplicate content across multiple files
- Use tool-specific formatting that won't work elsewhere
- Make assumptions about versions - specify exact versions

## Rule Improvement Triggers

Update documentation when you notice:
- New code patterns not covered by existing docs
- Repeated similar implementations across files
- Common error patterns that could be prevented
- New libraries or tools being used consistently
- Emerging best practices in the codebase

## Analysis Process

When updating documentation:
1. Compare new code with existing rules
2. Identify patterns that should be standardized
3. Look for references to external documentation
4. Check for consistent error handling patterns
5. Monitor test patterns and coverage

## Rule Updates

### Add New Documentation When:
- A new technology/pattern is used in 3+ files
- Common bugs could be prevented by documentation
- Code reviews repeatedly mention the same feedback
- New security or performance patterns emerge

### Modify Existing Documentation When:
- Better examples exist in the codebase
- Additional edge cases are discovered
- Related documentation has been updated
- Implementation details have changed

## Quality Checks

Before committing documentation changes:
- [ ] Documentation is actionable and specific
- [ ] Examples come from actual code
- [ ] References are up to date
- [ ] Patterns are consistently enforced
- [ ] Cross-references work correctly
- [ ] Version numbers are exact and current

## Continuous Improvement

- Monitor code review comments
- Track common development questions
- Update docs after major refactors
- Add links to relevant documentation
- Cross-reference related docs

## Deprecation

When patterns become outdated:
1. Mark outdated patterns as deprecated
2. Remove docs that no longer apply
3. Update references to deprecated patterns
4. Document migration paths for old patterns

## Synchronization

### Single Source of Truth
- Each piece of information should exist in exactly ONE location
- Other files should reference the source, not duplicate it
- Example: Version numbers live in `core/technology-stack.md`, other files reference it

### Cross-Tool Compatibility
- **CLAUDE.md**: Main instructions for Claude Code users (references `.ai/` files)
- **.cursor/rules/**: Single master file pointing to `.ai/` documentation
- **Both tools**: Should get same information from `.ai/` directory

### When to Update What

**Version Changes** (Laravel, PHP, packages):
1. Update `core/technology-stack.md` (single source)
2. Verify CLAUDE.md references it correctly
3. No other files should duplicate version numbers

**Workflow Changes** (commands, setup):
1. Update `development/workflow.md`
2. Ensure CLAUDE.md quick reference is updated
3. Verify all cross-references work

**Pattern Changes** (how to write code):
1. Update appropriate file in `patterns/`
2. Add/update examples from real codebase
3. Cross-reference from related docs

## Documentation Files

Keep documentation files only when explicitly needed. Don't create docs that merely describe obvious functionality - the code itself should be clear.

## Breaking Changes

When making breaking changes to documentation structure:
1. Update this maintaining-docs.md file
2. Update `.ai/README.md` navigation
3. Update CLAUDE.md references
4. Update `.cursor/rules/coolify-ai-docs.mdc`
5. Test all cross-references still work
6. Document the changes in sync-guide.md
