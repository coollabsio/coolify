# AI Instructions Synchronization Guide

This document explains how AI instructions are organized and synchronized across different AI tools used with Coolify.

## Overview

Coolify maintains AI instructions in two parallel systems:

1. **CLAUDE.md** - For Claude Code (claude.ai/code)
2. **.cursor/rules/** - For Cursor IDE and other AI assistants

Both systems share core principles but are optimized for their respective workflows.

## Structure

### CLAUDE.md
- **Purpose**: Condensed, workflow-focused guide for Claude Code
- **Format**: Single markdown file
- **Includes**:
  - Quick-reference development commands
  - High-level architecture overview
  - Core patterns and guidelines
  - Embedded Laravel Boost guidelines
  - References to detailed .cursor/rules/ documentation

### .cursor/rules/
- **Purpose**: Detailed, topic-specific documentation
- **Format**: Multiple .mdc files organized by topic
- **Structure**:
  - `README.mdc` - Main index and overview
  - `cursor_rules.mdc` - Maintenance guidelines
  - Topic-specific files (testing-patterns.mdc, security-patterns.mdc, etc.)
- **Used by**: Cursor IDE, Claude Code (for detailed reference), other AI assistants

## Cross-References

Both systems reference each other:

- **CLAUDE.md** → references `.cursor/rules/` for detailed documentation
- **.cursor/rules/README.mdc** → references `CLAUDE.md` for Claude Code workflow
- **.cursor/rules/cursor_rules.mdc** → notes that changes should sync with CLAUDE.md

## Maintaining Consistency

When updating AI instructions, follow these guidelines:

### 1. Core Principles (MUST be consistent)
- Laravel version (currently Laravel 12)
- PHP version (8.4)
- Testing execution rules (Docker for Feature tests, mocking for Unit tests)
- Security patterns and authorization requirements
- Code style requirements (Pint, PSR-12)

### 2. Where to Make Changes

**For workflow changes** (how to run commands, development setup):
- Primary: `CLAUDE.md`
- Secondary: `.cursor/rules/development-workflow.mdc`

**For architectural patterns** (how code should be structured):
- Primary: `.cursor/rules/` topic files
- Secondary: Reference in `CLAUDE.md` "Additional Documentation" section

**For testing patterns**:
- Both: Must be synchronized
- `CLAUDE.md` - Contains condensed testing execution rules
- `.cursor/rules/testing-patterns.mdc` - Contains detailed examples and patterns

### 3. Update Checklist

When making significant changes:

- [ ] Identify if change affects core principles (version numbers, critical patterns)
- [ ] Update primary location (CLAUDE.md or .cursor/rules/)
- [ ] Check if update affects cross-referenced content
- [ ] Update secondary location if needed
- [ ] Verify cross-references are still accurate
- [ ] Run: `./vendor/bin/pint CLAUDE.md .cursor/rules/*.mdc` (if applicable)

### 4. Common Inconsistencies to Watch

- **Version numbers**: Laravel, PHP, package versions
- **Testing instructions**: Docker execution requirements
- **File paths**: Ensure relative paths work from root
- **Command syntax**: Docker commands, artisan commands
- **Architecture decisions**: Laravel 10 structure vs Laravel 12+ structure

## File Organization

```
/
├── CLAUDE.md                          # Claude Code instructions (condensed)
├── .AI_INSTRUCTIONS_SYNC.md          # This file
└── .cursor/
    └── rules/
        ├── README.mdc                 # Index and overview
        ├── cursor_rules.mdc           # Maintenance guide
        ├── testing-patterns.mdc       # Testing details
        ├── development-workflow.mdc   # Dev setup details
        ├── security-patterns.mdc      # Security details
        ├── application-architecture.mdc
        ├── deployment-architecture.mdc
        ├── database-patterns.mdc
        ├── frontend-patterns.mdc
        ├── api-and-routing.mdc
        ├── form-components.mdc
        ├── technology-stack.mdc
        ├── project-overview.mdc
        └── laravel-boost.mdc          # Laravel-specific patterns
```

## Recent Updates

### 2025-10-07
- ✅ Added cross-references between CLAUDE.md and .cursor/rules/
- ✅ Synchronized Laravel version (12) across all files
- ✅ Added comprehensive testing execution rules (Docker for Feature tests)
- ✅ Added test design philosophy (prefer mocking over database)
- ✅ Fixed inconsistencies in testing documentation
- ✅ Created this synchronization guide

## Maintenance Commands

```bash
# Check for version inconsistencies
grep -r "Laravel [0-9]" CLAUDE.md .cursor/rules/*.mdc

# Check for PHP version consistency
grep -r "PHP [0-9]" CLAUDE.md .cursor/rules/*.mdc

# Format all documentation
./vendor/bin/pint CLAUDE.md .cursor/rules/*.mdc

# Search for specific patterns across all docs
grep -r "pattern_to_check" CLAUDE.md .cursor/rules/
```

## Contributing

When contributing documentation:

1. Check both CLAUDE.md and .cursor/rules/ for existing documentation
2. Add to appropriate location(s) based on guidelines above
3. Add cross-references if creating new patterns
4. Update this file if changing organizational structure
5. Verify consistency before submitting PR

## Questions?

If unsure about where to document something:

- **Quick reference / workflow** → CLAUDE.md
- **Detailed patterns / examples** → .cursor/rules/[topic].mdc
- **Both?** → Start with .cursor/rules/, then reference in CLAUDE.md

When in doubt, prefer detailed documentation in .cursor/rules/ and concise references in CLAUDE.md.
