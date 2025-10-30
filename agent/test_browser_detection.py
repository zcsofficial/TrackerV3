import os
import sys
sys.path.insert(0, os.path.dirname(__file__))

print("Testing browser tab detection...")
print("=" * 50)

try:
    import browser_monitoring
    print("OK - Browser monitoring module imported")
    
    os.environ['TRACKER_WEBSITE_MONITORING'] = '1'
    
    print("\nScanning for browser tabs...")
    tabs = browser_monitoring._read_browser_tabs_windows()
    
    print(f"\nFound {len(tabs)} browser tab(s):")
    for i, tab in enumerate(tabs, 1):
        print(f"\n{i}. Domain: {tab.get('domain', 'Unknown')}")
        print(f"   Browser: {tab.get('browser', 'Unknown')}")
        print(f"   URL: {tab.get('url', 'N/A')}")
        print(f"   Title: {tab.get('title', 'N/A')[:60]}")
    
    if len(tabs) == 0:
        print("\nWARNING: No browser tabs detected!")
        print("Make sure:")
        print("  1. A browser (Chrome, Edge, Firefox) is open with tabs")
        print("  2. Windows API (pywin32) is installed: pip install pywin32")
        print("  3. Browser window is visible (not minimized)")
    
    print("\n" + "=" * 50)
    print("Test complete!")
    
except Exception as e:
    import traceback
    print(f"ERROR: {e}")
    traceback.print_exc()


