#!/usr/bin/env python3
"""
Test script to verify pixel_import.php is working correctly
"""
import os
import json
import subprocess
import tempfile

# Create a minimal test payload with one event
test_event = {
    "uuid": "test-uuid-12345",
    "event_type": "page_view",
    "event_timestamp": "2026-01-07T03:00:00Z",
    "first_name": "Test",
    "last_name": "User",
    "pixel_id": "test-pixel-id"
}

payload = {"events": [test_event]}

# Write to temp file
with tempfile.NamedTemporaryFile(mode='w', delete=False, suffix='.json') as f:
    json.dump(payload, f)
    temp_path = f.name

print(f"Test payload written to: {temp_path}")
print(f"Payload content: {json.dumps(payload, indent=2)}")

# Set up environment
env = os.environ.copy()
env["CLIENT_NAME"] = "VettaFi"
env["REQUEST_METHOD"] = "POST"
env["DB_HOST"] = os.environ.get("MYSQL_HOST", "34.26.61.148")

print(f"\nEnvironment:")
print(f"  CLIENT_NAME: {env['CLIENT_NAME']}")
print(f"  DB_HOST: {env['DB_HOST']}")

# Run pixel_import.php
script_path = "pixel_import.php"
print(f"\nRunning: php {script_path} {temp_path}")

process = subprocess.Popen(
    ["php", script_path, temp_path],
    stdout=subprocess.PIPE,
    stderr=subprocess.PIPE,
    env=env,
    text=True,
    cwd=os.path.dirname(os.path.abspath(__file__))
)

stdout, stderr = process.communicate()

print(f"\n=== STDOUT ===")
print(stdout)

print(f"\n=== STDERR ===")
print(stderr)

print(f"\n=== EXIT CODE ===")
print(process.returncode)

# Cleanup
os.remove(temp_path)
