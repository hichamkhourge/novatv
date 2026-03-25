#!/bin/bash
# Test script for running renewal with environment variables loaded from .env

# Get the directory where this script is located
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

# Load environment variables from .env file
if [ -f "$PROJECT_ROOT/.env" ]; then
    echo "Loading environment variables from .env..."
    export $(cat "$PROJECT_ROOT/.env" | grep -v '^#' | grep -v '^$' | xargs)
else
    echo "Error: .env file not found at $PROJECT_ROOT/.env"
    exit 1
fi

# Run the renewal script with provided arguments
echo "Running ugeen_renew_user.py with user ID: $1"
python3 "$SCRIPT_DIR/ugeen_renew_user.py" --user-id "$1"
