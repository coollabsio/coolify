# AI Instructions Synchronization Guide

This document explains how AI instructions are organized and synchronized across different AI tools used with Coolify.

## Overview

Coolify maintains AI instructions with a **single source of truth** approach:

1. **CLAUDE.md** - Main entry point for Claude Code (references `.ai/` directory)
2. **.cursor/rules/coolify-ai-docs.mdc** - Master reference file for Cursor IDE (references `.ai/` directory)
3. **.ai/** - Single source of truth containing all detailed documentation

All AI tools (Claude Code, Cursor IDE, etc.) reference the same `.ai/` directory to ensure consistency.

## Structure

### CLAUDE.md (Root Directory)
- **Purpose**: Entry point for Claude Code with quick-reference guide
- **Format**: Single markdown file
- **Includes**:
  - Quick-reference development commands
  - High-level architecture overview
  - Essential patterns and guidelines
  - References to detailed `.ai/` documentation

### .cursor/rules/coolify-ai-docs.mdc
- **Purpose**: Master reference file for Cursor IDE
- **Format**: Single .mdc file with frontmatter
- **Content**: Quick decision tree and references to `.ai/` directory
- **Note**: Replaces all previous topic-specific .mdc files

### .ai/ Directory (Single Source of Truth)
- **Purpose**: All detailed, topic-specific documentation
- **Format**: Organized markdown files by category
- **Structure**:
  ```
  .ai/
  ├── README.md                    # Navigation hub
  ├── core/                        # Project information
  │   ├── technology-stack.md      # Version numbers (SINGLE SOURCE OF TRUTH)
  │   ├── project-overview.md
  │   ├── application-architecture.md
  │   └── deployment-architecture.md
  ├── development/                 # Development practices
  │   ├── development-workflow.md
  │   ├── testing-patterns.md
  │   └── laravel-boost.md
  ├── patterns/                    # Code patterns
  │   ├── database-patterns.md
  │   ├── frontend-patterns.md
  │   ├── security-patterns.md
  │   ├── form-components.md
  │   └── api-and-routing.md
  └── meta/                        # Documentation guides
      ├── maintaining-docs.md
      └── sync-guide.md (this file)
  ```
- **Used by**: All AI tools through CLAUDE.md or coolify-ai-docs.mdc

## Cross-References

All systems reference the `.ai/` directory as the source of truth:

- **CLAUDE.md** → references `.ai/` files for detailed documentation
- **.cursor/rules/coolify-ai-docs.mdc** → references `.ai/` files for detailed documentation
- **.ai/README.md** → provides navigation to all documentation

## Maintaining Consistency

### 1. Core Principles (MUST be consistent)

These are defined ONCE in `.ai/core/technology-stack.md`:
- Laravel version (currently Laravel 12.4.1)
- PHP version (8.4.7)
- All package versions (Livewire 3.5.20, Tailwind 4.1.4, etc.)

**Exception**: CLAUDE.md is permitted to show essential version numbers as a quick reference for convenience. These must stay synchronized with `technology-stack.md`. When updating versions, update both locations.

Other critical patterns defined in `.ai/`:
- Testing execution rules (Docker for Feature tests, mocking for Unit tests)
- Security patterns and authorization requirements
- Code style requirements (Pint, PSR-12)

### 2. Where to Make Changes

**For version numbers** (Laravel, PHP, packages):
1. Update `.ai/core/technology-stack.md` (single source of truth)
2. Update CLAUDE.md quick reference section (essential versions only)
3. Verify both files stay synchronized
4. Never duplicate version numbers in other locations

**For workflow changes** (how to run commands, development setup):
1. Update `.ai/development/development-workflow.md`
2. Update quick reference in CLAUDE.md if needed
3. Verify `.cursor/rules/coolify-ai-docs.mdc` references are correct

**For architectural patterns** (how code should be structured):
1. Update appropriate file in `.ai/core/`
2. Add cross-references from related docs
3. Update CLAUDE.md if it needs to highlight this pattern

**For code patterns** (how to write code):
1. Update appropriate file in `.ai/patterns/`
2. Add examples from real codebase
3. Cross-reference from related docs

**For testing patterns**:
1. Update `.ai/development/testing-patterns.md`
2. Ensure CLAUDE.md testing section references it

### 3. Update Checklist

When making significant changes:

- [ ] Identify if change affects core principles (version numbers, critical patterns)
- [ ] Update primary location in `.ai/` directory
- [ ] Check if CLAUDE.md needs quick-reference update
- [ ] Verify `.cursor/rules/coolify-ai-docs.mdc` references are still accurate
- [ ] Update cross-references in related `.ai/` files
- [ ] Verify all relative paths work correctly
- [ ] Test links in markdown files
- [ ] Run: `./vendor/bin/pint` on modified files (if applicable)

### 4. Common Inconsistencies to Watch

- **Version numbers**: Should ONLY exist in `.ai/core/technology-stack.md`
- **Testing instructions**: Docker execution requirements must be consistent
- **File paths**: Ensure relative paths work from their location
- **Command syntax**: Docker commands, artisan commands must be accurate
- **Cross-references**: Links must point to current file locations

## File Organization

```
/
├── CLAUDE.md                          # Claude Code entry point
├── .AI_INSTRUCTIONS_SYNC.md           # Redirect to this file
├── .cursor/
│   └── rules/
│       └── coolify-ai-docs.mdc        # Cursor IDE master reference
└── .ai/                               # SINGLE SOURCE OF TRUTH
    ├── README.md                       # Navigation hub
    ├── core/                           # Project information
    ├── development/                    # Development practices
    ├── patterns/                       # Code patterns
    └── meta/                          # Documentation guides
```

## Recent Updates

### 2025-11-18 - Documentation Consolidation
- ✅ Consolidated all documentation into `.ai/` directory
- ✅ Created single source of truth for version numbers
- ✅ Reduced CLAUDE.md from 719 to 319 lines
- ✅ Replaced 11 .cursor/rules/*.mdc files with single coolify-ai-docs.mdc
- ✅ Organized by topic: core/, development/, patterns/, meta/
- ✅ Standardized version numbers (Laravel 12.4.1, PHP 8.4.7, Tailwind 4.1.4)
- ✅ Created comprehensive navigation with .ai/README.md

### 2025-10-07
- ✅ Added cross-references between CLAUDE.md and .cursor/rules/
- ✅ Synchronized Laravel version (12) across all files
- ✅ Added comprehensive testing execution rules (Docker for Feature tests)
- ✅ Added test design philosophy (prefer mocking over database)
- ✅ Fixed inconsistencies in testing documentation

## Maintenance Commands

```bash
# Check for version inconsistencies (should only be in technology-stack.md)
# Note: CLAUDE.md is allowed to show quick reference versions
grep -r "Laravel 12" .ai/ CLAUDE.md .cursor/rules/coolify-ai-docs.mdc
grep -r "PHP 8.4" .ai/ CLAUDE.md .cursor/rules/coolify-ai-docs.mdc

# Check for broken cross-references to old .mdc files
grep -r "\.cursor/rules/.*\.mdc" .ai/ CLAUDE.md

# Format all documentation
./vendor/bin/pint CLAUDE.md .ai/**/*.md

# Search for specific patterns across all docs
grep -r "pattern_to_check" CLAUDE.md .ai/ .cursor/rules/

# Verify all markdown links work (from repository root)
find .ai -name "*.md" -exec grep -H "\[.*\](.*)" {} \;
```

## Contributing

When contributing documentation:

1. **Check `.ai/` directory** for existing documentation
2. **Update `.ai/` files** - this is the single source of truth
3. **Use cross-references** - never duplicate content
4. **Update CLAUDE.md** if adding critical quick-reference information
5. **Verify `.cursor/rules/coolify-ai-docs.mdc`** still references correctly
6. **Test all links** work from their respective locations
7. **Update this sync-guide.md** if changing organizational structure
8. **Verify consistency** before submitting PR

## Questions?

If unsure about where to document something:

- **Version numbers** → `.ai/core/technology-stack.md` (ONLY location)
- **Quick reference / commands** → CLAUDE.md + `.ai/development/development-workflow.md`
- **Detailed patterns / examples** → `.ai/patterns/[topic].md`
- **Architecture / concepts** → `.ai/core/[topic].md`
- **Development practices** → `.ai/development/[topic].md`
- **Documentation guides** → `.ai/meta/[topic].md`

**Golden Rule**: Each piece of information exists in ONE location in `.ai/`, other files reference it.

When in doubt, prefer detailed documentation in `.ai/` and lightweight references in CLAUDE.md and coolify-ai-docs.mdc.
