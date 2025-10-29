# Mobile Phone Detection - Installation Instructions

## Problem
Mobile phones and some USB devices are not being detected because the `wmi` Python library is not installed.

## Solution
Install the required libraries for comprehensive device detection:

### Option 1: Install via pip (Recommended)
```bash
pip install wmi pywin32
```

### Option 2: If using the installer
The installer has been updated to include `wmi` and `pywin32` in requirements.txt.
Run the installer again or manually install:
```bash
pip install -r requirements.txt
```

## What These Libraries Do

1. **`wmi`** - Windows Management Instrumentation
   - Allows Python to query Windows system information
   - Required for detecting mobile phones, MTP/PTP devices, and all USB peripherals
   - Essential for comprehensive device monitoring

2. **`pywin32`** - Python for Windows Extensions
   - Provides Windows API access
   - Needed for drive enumeration and device detection
   - Used as fallback method if WMI is not available

## After Installation

1. Restart the agent
2. Connect your mobile phone via USB
3. Check agent logs for detection messages
4. Device should appear in the UI within 2-4 seconds

## Verification

Run the test script to verify detection is working:
```bash
cd agent
python test_device_detection.py
```

You should see your connected devices listed.

## Note

Without WMI, only removable drives (USB flash drives that appear as disk drives) will be detected.
Mobile phones, cameras, and other MTP/PTP devices require WMI for detection.

