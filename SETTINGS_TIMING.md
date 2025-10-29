# Settings Update Timing - TrackerV3 Agent

## How Settings Updates Work

When you change settings in the UI (e.g., enable/disable screenshots, change sync interval), here's how they propagate to agents:

### 1. **Update Flow**
   - Admin changes settings in UI (`settings.php`)
   - Settings are saved to database immediately
   - Agent syncs with server (via `api/ingest.php`) during its regular sync cycle
   - Server returns current settings in the sync response
   - Agent updates its environment variables with new settings
   - Agent logs the changes

### 2. **Timing Breakdown**

**Minimum Time**: One sync interval (default: 60 seconds)
- If agent just synced: up to 60 seconds
- If agent is about to sync: immediate (< 5 seconds)

**Typical Time**: 30-60 seconds (half to full sync interval on average)

**Maximum Time**: One full sync interval + processing time

### 3. **Settings That Apply Immediately**
- `sync_interval_seconds` - Applied on next sync cycle
- `parallel_sync_workers` - Applied on next sync
- `delete_screenshots_after_sync` - Applied on next sync
- `screenshots_enabled` - Applied immediately if interval elapsed
- `screenshot_interval_seconds` - Applied on next screenshot check
- `device_monitoring_enabled` - Applied immediately on next device scan (every 5 seconds)

### 4. **Verification**

Check agent logs (`agent/data/agent.log`) to see:
- Settings updates: `Settings updated from server: ...`
- Current active settings: `Current active settings: ...`
- Initial settings on startup: `Initial agent settings: ...`

### 5. **Real-time Monitoring**

Device monitoring now scans every **5 seconds** (instead of 30 seconds) for real-time detection of connected devices.

### 6. **Best Practices**

- To ensure immediate update, wait at least 1 sync interval after changing settings
- Check agent logs to verify settings have been applied
- For device monitoring, changes take effect within 5-10 seconds

## Example Log Output

```
2025-10-29 16:20:25 | INFO | Initial agent settings: sync_interval=60s, screenshots=ENABLED (interval=300s), device_monitoring=ENABLED
2025-10-29 16:20:25 | INFO | NOTE: Settings are updated from server during each sync cycle (current sync_interval=60s). Changes in UI typically reflect within one sync interval.
2025-10-29 16:21:25 | INFO | Settings updated from server: screenshots=1→0 (DISABLED), device_monitoring=0→1 (ENABLED)
2025-10-29 16:21:25 | INFO | Current active settings: sync_interval=60s, parallel_workers=1, delete_screenshots=1, device_monitoring=1, screenshots_enabled=0, screenshot_interval=300s
```

