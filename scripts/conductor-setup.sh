#!/bin/bash
set -e

# Validate CONDUCTOR_ROOT_PATH is set and valid before any operations
if [ -z "$CONDUCTOR_ROOT_PATH" ]; then
    echo "ERROR: CONDUCTOR_ROOT_PATH environment variable is not set"
    echo "This script must be run by Conductor with CONDUCTOR_ROOT_PATH set to the main repository path"
    exit 1
fi

if [ ! -d "$CONDUCTOR_ROOT_PATH" ]; then
    echo "ERROR: CONDUCTOR_ROOT_PATH ($CONDUCTOR_ROOT_PATH) is not a valid directory"
    exit 1
fi

# Copy .env file
cp "$CONDUCTOR_ROOT_PATH/.env" .env

# Setup shared dependencies via symlinks to main repo
echo "Setting up shared node_modules and vendor directories..."

# Ensure main repo has the directories
mkdir -p "$CONDUCTOR_ROOT_PATH/node_modules"
mkdir -p "$CONDUCTOR_ROOT_PATH/vendor"

# Get current worktree path
WORKTREE_PATH=$(pwd)

# Safety check 1: ensure WORKTREE_PATH is valid
if [ -z "$WORKTREE_PATH" ]; then
    echo "ERROR: WORKTREE_PATH is empty"
    exit 1
fi

# Safety check 2: CRITICAL FIRST - blacklist system directories
# This check runs BEFORE the positive check to prevent dangerous operations
# even if someone misconfigures CONDUCTOR_ROOT_PATH
case "$WORKTREE_PATH" in
    /|/bin|/sbin|/usr|/usr/*|/etc|/etc/*|/var|/var/*|/System|/System/*|/Library|/Library/*|/Applications|/Applications/*|"$HOME")
        echo "ERROR: WORKTREE_PATH ($WORKTREE_PATH) is in a dangerous system location"
        exit 1
        ;;
esac

# Safety check 3: positive check - verify we're under CONDUCTOR_ROOT_PATH
case "$WORKTREE_PATH" in
    "$CONDUCTOR_ROOT_PATH"|"$CONDUCTOR_ROOT_PATH"/.conductor/*)
        # Valid: either main repo or under .conductor/
        ;;
    *)
        echo "ERROR: WORKTREE_PATH ($WORKTREE_PATH) is not under CONDUCTOR_ROOT_PATH ($CONDUCTOR_ROOT_PATH)"
        exit 1
        ;;
esac

# Safety check 4: verify we're in a git repository
if [ ! -f ".git" ] && [ ! -d ".git" ]; then
    echo "ERROR: Not in a git repository"
    exit 1
fi

# Remove existing directories/symlinks if they exist
# For symlinks: use 'rm' without -r to remove the symlink itself (not following it)
# For directories: use 'rm -rf' to remove the directory and contents
if [ -L "node_modules" ]; then
    # It's a symlink - remove it without following (no -r flag)
    rm "$WORKTREE_PATH/node_modules"
elif [ -e "node_modules" ]; then
    # It's a regular directory or file - safe to use -rf
    rm -rf "$WORKTREE_PATH/node_modules"
fi

if [ -L "vendor" ]; then
    # It's a symlink - remove it without following (no -r flag)
    rm "$WORKTREE_PATH/vendor"
elif [ -e "vendor" ]; then
    # It's a regular directory or file - safe to use -rf
    rm -rf "$WORKTREE_PATH/vendor"
fi

# Calculate relative path from worktree to main repo
# Use bash-native approach: try realpath first (GNU coreutils), fallback to perl
if command -v realpath &> /dev/null && realpath --relative-to / / &> /dev/null 2>&1; then
    # GNU coreutils realpath with --relative-to support
    RELATIVE_PATH=$(realpath --relative-to="$WORKTREE_PATH" "$CONDUCTOR_ROOT_PATH")
else
    # Fallback: use perl which is standard on macOS and most Unix systems
    RELATIVE_PATH=$(perl -e 'use File::Spec; print File::Spec->abs2rel($ARGV[0], $ARGV[1])' "$CONDUCTOR_ROOT_PATH" "$WORKTREE_PATH")
fi

# Create symlinks to main repo's node_modules and vendor
ln -sf "$RELATIVE_PATH/node_modules" node_modules
ln -sf "$RELATIVE_PATH/vendor" vendor

echo "✓ Shared dependencies linked successfully"
echo "  node_modules -> $RELATIVE_PATH/node_modules"
echo "  vendor -> $RELATIVE_PATH/vendor"