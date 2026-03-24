#!/bin/sh
# Minimal entrypoint - USE ONLY FOR DEBUGGING
# This skips ALL initialization and just starts PHP-FPM
# Use this if the main entrypoint is failing

echo "=========================================="
echo "MINIMAL ENTRYPOINT - DEBUG MODE"
echo "Skipping all initialization"
echo "Starting PHP-FPM directly..."
echo "=========================================="

# Just start PHP-FPM
exec "$@"
