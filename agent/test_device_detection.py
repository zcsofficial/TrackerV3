"""
Quick test script to verify device detection is working
"""
import sys
import os
sys.path.insert(0, os.path.dirname(__file__))

print("Testing device detection...")
print("=" * 50)

try:
    import monitoring
    print("OK - Monitoring module imported")
    
    # Force enable monitoring for test
    os.environ['TRACKER_DEVICE_MONITORING'] = '1'
    
    print("\nScanning for devices...")
    devices = monitoring._get_usb_devices()
    
    print(f"\nFound {len(devices)} device(s):")
    for i, device in enumerate(devices, 1):
        print(f"\n{i}. {device.get('name', 'Unknown')}")
        print(f"   Type: {device.get('type', 'N/A')}")
        print(f"   VID: {device.get('vendor_id', 'N/A')}")
        print(f"   PID: {device.get('product_id', 'N/A')}")
        print(f"   Path: {device.get('path', 'N/A')}")
    
    if len(devices) == 0:
        print("\nWARNING: No devices detected!")
        print("Make sure:")
        print("  1. A USB device (phone, drive, etc.) is connected")
        print("  2. WMI is installed: pip install wmi")
        print("  3. pywin32 is installed: pip install pywin32")
    
    print("\n" + "=" * 50)
    print("Test complete!")
    
except Exception as e:
    import traceback
    print(f"ERROR: {e}")
    traceback.print_exc()

