---
name: debugging-output-and-previewing-html-using-ray
description: Use when user says "send to Ray," "show in Ray," "debug in Ray," "log to Ray," "display in Ray," or wants to visualize data, debug output, or show diagrams in the Ray desktop application.
metadata:
  author: Spatie
  tags:
    - debugging
    - logging
    - visualization
    - ray
---

# Ray Skill

## Overview

Ray is Spatie's desktop debugging application for developers. Send data directly to Ray by making HTTP requests to its local server.

This can be useful for debugging applications, or to preview design, logos, or other visual content. 

This is what the `ray()` PHP function does under the hood.

## Connection Details

| Setting | Default | Environment Variable |
|---------|---------|---------------------|
| Host | `localhost` | `RAY_HOST` |
| Port | `23517` | `RAY_PORT` |
| URL | `http://localhost:23517/` | - |

## Request Format

**Method:** POST
**Content-Type:** `application/json`
**User-Agent:** `Ray 1.0`

### Basic Request Structure

```json
{
  "uuid": "unique-identifier-for-this-ray-instance",
  "payloads": [
    {
      "type": "log",
      "content": { },
      "origin": {
        "file": "/path/to/file.php",
        "line_number": 42,
        "hostname": "my-machine"
      }
    }
  ],
  "meta": {
    "ray_package_version": "1.0.0"
  }
}
```

### Fields

| Field | Type | Description |
|-------|------|-------------|
| `uuid` | string | Unique identifier for this Ray instance. Reuse the same UUID to update an existing entry. |
| `payloads` | array | Array of payload objects to send |
| `meta` | object | Optional metadata (ray_package_version, project_name, php_version) |

### Origin Object

Every payload includes origin information:

```json
{
  "file": "/Users/dev/project/app/Controller.php",
  "line_number": 42,
  "hostname": "dev-machine"
}
```

## Payload Types

### Log (Send Values)

```json
{
  "type": "log",
  "content": {
    "values": ["Hello World", 42, {"key": "value"}]
  },
  "origin": { "file": "test.php", "line_number": 1, "hostname": "localhost" }
}
```

### Custom (HTML/Text Content)

```json
{
  "type": "custom",
  "content": {
    "content": "<h1>HTML Content</h1><p>With formatting</p>",
    "label": "My Label"
  },
  "origin": { "file": "test.php", "line_number": 1, "hostname": "localhost" }
}
```

### Table

```json
{
  "type": "table",
  "content": {
    "values": {"name": "John", "email": "john@example.com", "age": 30},
    "label": "User Data"
  },
  "origin": { "file": "test.php", "line_number": 1, "hostname": "localhost" }
}
```

### Color

Set the color of the preceding log entry:

```json
{
  "type": "color",
  "content": {
    "color": "green"
  },
  "origin": { "file": "test.php", "line_number": 1, "hostname": "localhost" }
}
```

**Available colors:** `green`, `orange`, `red`, `purple`, `blue`, `gray`

### Screen Color

Set the background color of the screen:

```json
{
  "type": "screen_color",
  "content": {
    "color": "green"
  },
  "origin": { "file": "test.php", "line_number": 1, "hostname": "localhost" }
}
```

### Label

Add a label to the entry:

```json
{
  "type": "label",
  "content": {
    "label": "Important"
  },
  "origin": { "file": "test.php", "line_number": 1, "hostname": "localhost" }
}
```

### Size

Set the size of the entry:

```json
{
  "type": "size",
  "content": {
    "size": "lg"
  },
  "origin": { "file": "test.php", "line_number": 1, "hostname": "localhost" }
}
```

**Available sizes:** `sm`, `lg`

### Notify (Desktop Notification)

```json
{
  "type": "notify",
  "content": {
    "value": "Task completed!"
  },
  "origin": { "file": "test.php", "line_number": 1, "hostname": "localhost" }
}
```

### New Screen

```json
{
  "type": "new_screen",
  "content": {
    "name": "Debug Session"
  },
  "origin": { "file": "test.php", "line_number": 1, "hostname": "localhost" }
}
```

### Measure (Timing)

```json
{
  "type": "measure",
  "content": {
    "name": "my-timer",
    "is_new_timer": true,
    "total_time": 0,
    "time_since_last_call": 0,
    "max_memory_usage_during_total_time": 0,
    "max_memory_usage_since_last_call": 0
  },
  "origin": { "file": "test.php", "line_number": 1, "hostname": "localhost" }
}
```

For subsequent measurements, set `is_new_timer: false` and provide actual timing values.

### Simple Payloads (No Content)

These payloads only need a `type` and empty `content`:

```json
{
  "type": "separator",
  "content": {},
  "origin": { "file": "test.php", "line_number": 1, "hostname": "localhost" }
}
```

| Type | Purpose |
|------|---------|
| `separator` | Add visual divider |
| `clear_all` | Clear all entries |
| `hide` | Hide this entry |
| `remove` | Remove this entry |
| `confetti` | Show confetti animation |
| `show_app` | Bring Ray to foreground |
| `hide_app` | Hide Ray window |

## Combining Multiple Payloads

Send multiple payloads in one request. Use the same `uuid` to apply modifiers (color, label, size) to a log entry:

```json
{
  "uuid": "abc-123",
  "payloads": [
    {
      "type": "log",
      "content": { "values": ["Important message"] },
      "origin": { "file": "test.php", "line_number": 1, "hostname": "localhost" }
    },
    {
      "type": "color",
      "content": { "color": "red" },
      "origin": { "file": "test.php", "line_number": 1, "hostname": "localhost" }
    },
    {
      "type": "label",
      "content": { "label": "ERROR" },
      "origin": { "file": "test.php", "line_number": 1, "hostname": "localhost" }
    },
    {
      "type": "size",
      "content": { "size": "lg" },
      "origin": { "file": "test.php", "line_number": 1, "hostname": "localhost" }
    }
  ],
  "meta": {}
}
```

## Example: Complete Request

Send a green, labeled log message:

```bash
curl -X POST http://localhost:23517/ \
  -H "Content-Type: application/json" \
  -H "User-Agent: Ray 1.0" \
  -d '{
    "uuid": "my-unique-id-123",
    "payloads": [
      {
        "type": "log",
        "content": {
          "values": ["User logged in", {"user_id": 42, "name": "John"}]
        },
        "origin": {
          "file": "/app/AuthController.php",
          "line_number": 55,
          "hostname": "dev-server"
        }
      },
      {
        "type": "color",
        "content": { "color": "green" },
        "origin": { "file": "/app/AuthController.php", "line_number": 55, "hostname": "dev-server" }
      },
      {
        "type": "label",
        "content": { "label": "Auth" },
        "origin": { "file": "/app/AuthController.php", "line_number": 55, "hostname": "dev-server" }
      }
    ],
    "meta": {
      "project_name": "my-app"
    }
  }'
```

## Availability Check

Before sending data, you can check if Ray is running:

```
GET http://localhost:23517/_availability_check
```

Ray responds with HTTP 404 when available (the endpoint doesn't exist, but the server is running).

## Getting Ray Information

### Get Windows

Retrieve information about all open Ray windows:

```
GET http://localhost:23517/windows
```

Returns an array of window objects with their IDs and names:

```json
[
  {"id": 1, "name": "Window 1"},
  {"id": 2, "name": "Debug Session"}
]
```

### Get Theme Colors

Retrieve the current theme colors being used by Ray:

```
GET http://localhost:23517/theme
```

Returns the theme information including color palette:

```json
{
  "name": "Dark",
  "colors": {
    "primary": "#000000",
    "secondary": "#1a1a1a",
    "accent": "#3b82f6"
  }
}
```

**Use Case:** When sending custom HTML content to Ray, use these theme colors to ensure your content matches Ray's current theme and looks visually integrated.

**Example:** Send HTML with matching colors:

```bash

# First, get the theme

THEME=$(curl -s http://localhost:23517/theme)
PRIMARY_COLOR=$(echo $THEME | jq -r '.colors.primary')

# Then send HTML using those colors

curl -X POST http://localhost:23517/ \
  -H "Content-Type: application/json" \
  -d '{
    "uuid": "theme-matched-html",
    "payloads": [{
      "type": "custom",
      "content": {
        "content": "<div style=\"background: '"$PRIMARY_COLOR"'; padding: 20px;\"><h1>Themed Content</h1></div>",
        "label": "Themed HTML"
      },
      "origin": {"file": "script.sh", "line_number": 1, "hostname": "localhost"}
    }]
  }'
```

## Payload Type Reference

| Type | Content Fields | Purpose |
|------|----------------|---------|
| `log` | `values` (array) | Send values to Ray |
| `custom` | `content`, `label` | HTML or text content |
| `table` | `values`, `label` | Display as table |
| `color` | `color` | Set entry color |
| `screen_color` | `color` | Set screen background |
| `label` | `label` | Add label to entry |
| `size` | `size` | Set entry size (sm/lg) |
| `notify` | `value` | Desktop notification |
| `new_screen` | `name` | Create new screen |
| `measure` | `name`, `is_new_timer`, timing fields | Performance timing |
| `separator` | (empty) | Visual divider |
| `clear_all` | (empty) | Clear all entries |
| `hide` | (empty) | Hide entry |
| `remove` | (empty) | Remove entry |
| `confetti` | (empty) | Confetti animation |
| `show_app` | (empty) | Show Ray window |
| `hide_app` | (empty) | Hide Ray window |