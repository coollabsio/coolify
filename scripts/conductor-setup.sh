#!/bin/bash
set -e

# Copy .env file
cp $CONDUCTOR_ROOT_PATH/.env .env

# Setup shared dependencies via symlinks to main repo
echo "Setting up shared node_modules and vendor directories..."

# Ensure main repo has the directories
mkdir -p "$CONDUCTOR_ROOT_PATH/node_modules"
mkdir -p "$CONDUCTOR_ROOT_PATH/vendor"

# Get current worktree path
WORKTREE_PATH=$(pwd)

# Safety check: ensure WORKTREE_PATH is valid and not a dangerous location
if [ -z "$WORKTREE_PATH" ] || [ "$WORKTREE_PATH" = "/" ] || [ "$WORKTREE_PATH" = "/Users" ] || [ "$WORKTREE_PATH" = "$HOME" ]; then
    echo "ERROR: Invalid or dangerous WORKTREE_PATH: $WORKTREE_PATH"
    exit 1
fi

# Additional safety: ensure we're in a git worktree
if [ ! -f ".git" ] && [ ! -d ".git" ]; then
    echo "ERROR: Not in a git repository"
    exit 1
fi

# Remove existing directories if they exist and are not symlinks
[ -d "node_modules" ] && [ ! -L "node_modules" ] && rm -rf "$WORKTREE_PATH/node_modules"
[ -d "vendor" ] && [ ! -L "vendor" ] && rm -rf "$WORKTREE_PATH/vendor"

# Calculate relative path from worktree to main repo
RELATIVE_PATH=$(python3 -c "import os.path; print(os.path.relpath('$CONDUCTOR_ROOT_PATH', '$WORKTREE_PATH'))")

# Create symlinks to main repo's node_modules and vendor
ln -sf "$RELATIVE_PATH/node_modules" node_modules
ln -sf "$RELATIVE_PATH/vendor" vendor

echo "✓ Shared dependencies linked successfully"
echo "  node_modules -> $RELATIVE_PATH/node_modules"
echo "  vendor -> $RELATIVE_PATH/vendor"