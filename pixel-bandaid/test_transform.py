#!/usr/bin/env python3
"""
Test the actual transformation and payload generation
"""
import json

# Simulate actual API response
api_event = {
    "UUID": "93fca771-4422-5501-a235-0d163470fa8b",
    "EVENT_TYPE": "click",
    "EVENT_TIMESTAMP": "2026-01-07T07:07:35Z",
    "FIRST_NAME": "Felix",
    "LAST_NAME": "Villasenor",
    "PIXEL_ID": "e66bb188-742b-4b12-afab-c090e4550d66"
}

# Apply transformation
def transform_event(api_event):
    """Transform API event from UPPERCASE to lowercase_snake_case."""
    transformed = {}
    for key, value in api_event.items():
        db_key = key.lower()
        transformed[db_key] = value
    return transformed

transformed = transform_event(api_event)

print("Original API event:")
print(json.dumps(api_event, indent=2))
print("\nTransformed event:")
print(json.dumps(transformed, indent=2))
print("\nPayload that would be sent to PHP:")
payload = {"events": [transformed]}
print(json.dumps(payload, indent=2))
print(f"\nUUID field present: {'uuid' in transformed}")
print(f"UUID value: {transformed.get('uuid', 'NOT FOUND')}")
