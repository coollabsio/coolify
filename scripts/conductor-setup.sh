#!/bin/bash
set -e

# Copy .env file
cp $CONDUCTOR_ROOT_PATH/.env .env

# Setup shared dependencies via symlinks
echo "Setting up shared node_modules and vendor directories..."

# Create shared-deps directory in main repository if it doesn't exist
SHARED_DEPS="$CONDUCTOR_ROOT_PATH/.shared-deps"
mkdir -p "$SHARED_DEPS/node_modules"
mkdir -p "$SHARED_DEPS/vendor"

# Remove existing directories if they exist and are not symlinks
[ -d "node_modules" ] && [ ! -L "node_modules" ] && rm -rf node_modules
[ -d "vendor" ] && [ ! -L "vendor" ] && rm -rf vendor

# Calculate relative path from worktree to shared deps
WORKTREE_PATH=$(pwd)
RELATIVE_PATH=$(python3 -c "import os.path; print(os.path.relpath('$SHARED_DEPS', '$WORKTREE_PATH'))")

# Create symlinks
ln -sf "$RELATIVE_PATH/node_modules" node_modules
ln -sf "$RELATIVE_PATH/vendor" vendor

echo "✓ Shared dependencies linked successfully"
echo "  node_modules -> $RELATIVE_PATH/node_modules"
echo "  vendor -> $RELATIVE_PATH/vendor"