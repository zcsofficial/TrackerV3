"""
Device Monitoring Module for TrackerV3 Agent
Monitors and logs external device connections
"""
import os
import sys
import time
import hashlib
import logging
import requests
import re
from datetime import datetime

# Add parent directory to path for imports
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

try:
    from .config import (
        DEVICE_CHECK_INTERVAL, DEVICE_API_URL, MACHINE_ID, USERNAME, HOSTNAME, is_device_monitoring_enabled
    )
    from .permission import is_device_blocked, is_device_allowed, get_device_permission
except ImportError:
    from config import (
        DEVICE_CHECK_INTERVAL, DEVICE_API_URL, MACHINE_ID, USERNAME, HOSTNAME, is_device_monitoring_enabled
    )
    from permission import is_device_blocked, is_device_allowed, get_device_permission

log = logging.getLogger('tracker_agent.monitoring')

# Track previously seen devices
_seen_devices = {}
_last_scan_time = 0
_alert_interval = 30  # Show alert every 30 seconds for blocked devices that are still connected

def _show_blocked_device_alert(device_name):
    """Show popup alert when device is blocked - NON-BLOCKING (runs in background thread)"""
    import threading
    import subprocess
    
    def _show_alert_thread():
        """Show alert in separate thread so it doesn't block agent"""
        if sys.platform == 'win32':
            # Try multiple methods to ensure popup shows
            popup_shown = False
            
            # Method 1: Try ctypes MessageBoxW (most reliable)
            try:
                import ctypes
                # Use 0 for desktop window - this should work from any thread
                result = ctypes.windll.user32.MessageBoxW(
                    0,  # NULL hWnd - desktop window
                    f"External device '{device_name}' is blocked by security policy.\n\nPlease contact your administrator to request device access.\n\nAgent continues tracking in background.",
                    "Device Blocked - TrackerV3 Agent",
                    0x10 | 0x0  # MB_ICONSTOP | MB_OK
                )
                log.info(f"Blocked device alert popup shown (result: {result}) for: {device_name}")
                popup_shown = True
            except Exception as e:
                log.warning(f"ctypes MessageBoxW failed: {e}, trying fallback methods...")
            
            # Method 2: PowerShell popup (if ctypes failed or as backup)
            if not popup_shown:
                try:
                    # Use Add-Type to load Windows Forms, then show message box
                    ps_script = f'''
Add-Type -AssemblyName System.Windows.Forms
[System.Windows.Forms.MessageBox]::Show(
    "External device '{device_name}' is blocked by security policy.`n`nPlease contact your administrator to request device access.`n`nAgent continues tracking in background.",
    "Device Blocked - TrackerV3 Agent",
    [System.Windows.Forms.MessageBoxButtons]::OK,
    [System.Windows.Forms.MessageBoxIcon]::Stop
)
'''
                    subprocess.Popen(
                        ['powershell', '-WindowStyle', 'Hidden', '-Command', ps_script],
                        creationflags=subprocess.CREATE_NO_WINDOW
                    )
                    log.info(f"Blocked device alert shown via PowerShell for: {device_name}")
                    popup_shown = True
                except Exception as e2:
                    log.warning(f"PowerShell popup failed: {e2}")
            
            # Method 3: Python tkinter (final fallback)
            if not popup_shown:
                try:
                    import tkinter as tk
                    from tkinter import messagebox
                    root = tk.Tk()
                    root.withdraw()  # Hide main window
                    messagebox.showerror(
                        "Device Blocked - TrackerV3 Agent",
                        f"External device '{device_name}' is blocked by security policy.\n\nPlease contact your administrator to request device access.\n\nAgent continues tracking in background."
                    )
                    root.destroy()
                    log.info(f"Blocked device alert shown via tkinter for: {device_name}")
                    popup_shown = True
                except Exception as e3:
                    log.error(f"All popup methods failed. Last error: {e3}")
            
            if not popup_shown:
                log.error(f"CRITICAL: Could not show popup alert for blocked device: {device_name}")
        
        elif sys.platform.startswith('linux'):
            try:
                import subprocess
                # Run in background - don't wait
                subprocess.Popen([
                    'zenity', '--error',
                    '--title=Device Blocked - TrackerV3 Agent',
                    f'--text=External device "{device_name}" is blocked by security policy.\n\nPlease contact your administrator to request device access.'
                ])
                log.info(f"Blocked device alert shown via zenity for: {device_name}")
            except Exception:
                try:
                    import subprocess
                    # Use notify-send (non-blocking)
                    subprocess.Popen([
                        'notify-send',
                        'Device Blocked - TrackerV3 Agent',
                        f'External device "{device_name}" is blocked by security policy. Contact administrator.'
                    ])
                    log.info(f"Blocked device alert shown via notify-send for: {device_name}")
                except Exception:
                    log.error(f"Could not show alert on Linux for: {device_name}")
        
        elif sys.platform == 'darwin':
            try:
                import subprocess
                # Run osascript in background
                subprocess.Popen([
                    'osascript', '-e',
                    f'display dialog "External device \'{device_name}\' is blocked by security policy.\\n\\nPlease contact your administrator to request device access." with title "Device Blocked - TrackerV3 Agent" buttons {{"OK"}} default button "OK" with icon stop'
                ])
                log.info(f"Blocked device alert shown via osascript for: {device_name}")
            except Exception as e:
                log.error(f"Could not show alert on macOS: {e}")
    
    # Launch alert in separate thread - agent continues immediately
    alert_thread = threading.Thread(target=_show_alert_thread, daemon=True, name=f"AlertThread-{device_name}")
    alert_thread.start()
    log.info(f"Blocked device alert thread started for: {device_name} (agent continues tracking)")

def _get_usb_devices():
    """Detect USB devices - platform specific with improved detection for mobile phones"""
    devices = []
    
    if sys.platform == 'win32':
        # Use multiple methods for comprehensive device detection
        detected_hashes = set()  # Track detected devices to avoid duplicates
        
        # Method 1: WMI - Win32_PnPEntity (Primary method)
        try:
            import wmi
            c = wmi.WMI()
            log.debug("Using WMI for device detection...")
            
            # Scan ALL PnP devices for USB connections
            try:
                device_count = 0
                for device in c.Win32_PnPEntity():
                    device_count += 1
                    try:
                        # Check if device is USB-related
                        pnp_id = getattr(device, 'PNPDeviceID', '') or ''
                        caption = getattr(device, 'Caption', '') or ''
                        name = getattr(device, 'Name', '') or caption
                        
                        # Filter for EXTERNAL USB devices ONLY - EXCLUDE internal hardware
                        pnp_upper = pnp_id.upper()
                        name_upper = name.upper()
                        caption_upper = caption.upper()
                        
                        # STRICT EXCLUSION: Internal hardware that should NEVER be monitored
                        # By default, exclude everything that is not explicitly external
                        is_internal_hardware = (
                            # USB Controllers and Hubs (all internal)
                            'ROOT HUB' in name_upper or
                            'USB ROOT HUB' in name_upper or
                            ('HUB' in name_upper and ('ROOT' in name_upper or 'USB' in name_upper)) or
                            'USB CONTROLLER' in name_upper or
                            ('USB' in name_upper and 'CONTROLLER' in name_upper) or
                            
                            # Enumerators and bus devices (all internal)
                            'ENUMERATOR' in name_upper or
                            'BUS ENUMERATOR' in name_upper or
                            'COMPOSITE BUS ENUMERATOR' in name_upper or
                            'UMBUS ROOT BUS ENUMERATOR' in name_upper or
                            
                            # Internal storage controllers
                            'STORAGE SPACES CONTROLLER' in name_upper or
                            'STORAGE SPACES' in name_upper or
                            'OPTANE' in name_upper or
                            ('SPI' in name_upper and 'CONTROLLER' in name_upper) or
                            ('FLASH' in name_upper and 'CONTROLLER' in name_upper) or
                            
                            # Built-in Bluetooth (all internal)
                            ('INTEL' in name_upper and 'BLUETOOTH' in name_upper) or
                            ('WIRELESS' in name_upper and 'BLUETOOTH' in name_upper) or
                            ('BLUETOOTH' in name_upper and 'INTERNAL' in caption_upper) or
                            
                            # Built-in cameras (all internal)
                            ('FHD CAMERA' in name_upper) or
                            ('IR CAMERA' in name_upper) or
                            ('CAMERA' in name_upper and 'FHD' in caption_upper) or
                            (name_upper.startswith('FHD') and 'CAMERA' in name_upper) or
                            
                            # Control devices (not actual devices)
                            ('PORTABLE DEVICE CONTROL' in name_upper) or
                            ('CONVERTED PORTABLE DEVICE CONTROL' in name_upper) or
                            ('CONTROL DEVICE' in name_upper) or
                            
                            # Generic USB Input Devices (exclude unless explicitly gaming/external)
                            (name_upper == 'USB INPUT DEVICE' and 
                             'GAMING' not in caption_upper and 
                             'EXTERNAL' not in caption_upper and
                             'STEELSERIES' not in caption_upper) or
                            
                            # USB Composite Devices - MOST are internal, exclude unless explicitly external
                            (name_upper == 'USB COMPOSITE DEVICE' and
                             'GAMING' not in caption_upper and
                             'EXTERNAL' not in caption_upper and
                             'STEELSERIES' not in caption_upper and
                             'VID_' not in pnp_id) or  # No VID usually means internal controller
                            
                            # APP Mode - typically internal camera controller, exclude
                            (name_upper == 'APP MODE') or  # APP Mode is almost always internal
                            
                            # Generic USB Input Device (unless gaming/external)
                            (name_upper == 'USB INPUT DEVICE' and 
                             'GAMING' not in caption_upper and 
                             'EXTERNAL' not in caption_upper and
                             'STEELSERIES' not in caption_upper) or
                            
                            # USB devices without VID/PID are usually internal controllers
                            (name_upper.startswith('USB ') and 
                             'VID_' not in pnp_id and 
                             'PID_' not in pnp_id and
                             'EXTERNAL' not in caption_upper and
                             'REMOVABLE' not in caption_upper) or
                            
                            # Anything with "COMPOSITE" without VID/PID is internal
                            ('COMPOSITE' in name_upper and 
                             'VID_' not in pnp_id and
                             'EXTERNAL' not in caption_upper)
                        )
                        
                        # Additional check: If PNP ID shows it's a hub or controller, exclude
                        if 'ROOT' in pnp_upper or 'HUB' in pnp_upper or 'ENUMERATOR' in pnp_upper:
                            # Only allow if explicitly marked as external AND has VID/PID
                            if not ('EXTERNAL' in caption_upper and 'VID_' in pnp_id and 'PID_' in pnp_id):
                                is_internal_hardware = True
                        
                        # Skip internal hardware immediately
                        if is_internal_hardware:
                            log.debug(f"  Skipping internal hardware: {name}")
                            continue
                        
                        # USB device must have BOTH VID (Vendor ID) AND PID (Product ID) to be considered external device
                        # Internal controllers often don't have proper VID/PID
                        # Exception: Mobile phones via MTP/WPD might not always show VID clearly, so we check differently
                        has_vid = 'VID_' in pnp_id and 'PID_' in pnp_id  # Require BOTH VID and PID
                        
                        # Check for ACTUAL external USB devices
                        # USB Mass Storage (external drives) - must have VID/PID
                        is_usb_storage = ('USBSTOR' in pnp_upper or 'USB\\VID_' in pnp_id) and has_vid
                        
                        # Mobile phones and portable devices (MTP/PTP/WPD - but NOT control devices)
                        # MTP/WPD devices are typically mobile phones, cameras, media players
                        is_portable_device = (
                            ('MTP' in pnp_upper or 'PTP' in pnp_upper or 'WPD' in pnp_upper) and
                            'CONTROL' not in name_upper and 
                            'ENUMERATOR' not in name_upper and
                            'CONVERTED' not in name_upper
                        )
                        
                        # External storage indicators
                        is_external_storage = (
                            'REMOVABLE' in caption_upper or
                            'FLASH' in name_upper or
                            'MEMORY STICK' in name_upper or
                            ('EXTERNAL' in caption_upper and 'DISK' in caption_upper)
                        )
                        
                        # Mobile phone specific indicators (must be external)
                        # Include device codes/models that might be phones (like A059, etc.)
                        # If device name is short alphanumeric (likely phone model) and has MTP/WPD, treat as phone
                        is_short_model_code = (
                            len(name.strip()) <= 10 and 
                            name.strip().replace(' ', '').isalnum() and
                            not name.strip().startswith('USB') and
                            ('MTP' in pnp_upper or 'WPD' in pnp_upper or 'PTP' in pnp_upper)
                        )
                        
                        is_mobile_phone = (
                            'PHONE' in name_upper or
                            'SMARTPHONE' in name_upper or
                            'ANDROID' in name_upper or
                            'IPHONE' in name_upper or
                            'IPAD' in name_upper or
                            ('TABLET' in name_upper and 'EXTERNAL' in caption_upper) or
                            ('SAMSUNG' in name_upper and ('PHONE' in name_upper or 'TABLET' in name_upper)) or
                            ('NOKIA' in name_upper and 'PHONE' in name_upper) or
                            ('LG' in name_upper and 'PHONE' in name_upper) or
                            is_short_model_code  # Include short model codes that use MTP/WPD
                        )
                        
                        # Gaming keyboards/mice (external peripherals)
                        # EXCLUDE: ALL keyboards unless explicitly marked as external USB device
                        # Most "Gaming Keyboard" devices are built-in laptop keyboards
                        # Only detect if it's clearly an external USB keyboard/mouse
                        is_external_peripheral = (
                            # Only detect if explicitly marked as external USB device
                            ('EXTERNAL' in caption_upper and 
                             ('KEYBOARD' in name_upper or 'MOUSE' in name_upper) and
                             ('USB' in pnp_upper or 'USB\\VID_' in pnp_id)) or
                            # Exclude SteelSeries keyboards (usually built-in)
                            # Only allow if explicitly marked as external USB device
                            ('STEELSERIES' in name_upper and 
                             'EXTERNAL' in caption_upper and
                             'USB\\VID_' in pnp_id and
                             'KEYBOARD' not in name_upper)  # Only non-keyboard SteelSeries devices
                        )
                        
                        # Additional exclusion: If it's a keyboard without explicit external marker, skip
                        if ('KEYBOARD' in name_upper or 'MOUSE' in name_upper):
                            if 'EXTERNAL' not in caption_upper and 'EXTERNAL' not in name_upper:
                                # Skip built-in keyboards/mice
                                log.debug(f"  Skipping built-in keyboard/mouse: {name}")
                                continue
                        
                        # Also check for devices that use WPD protocol - these are often phones
                        # Look for devices with "Portable Device" or "Media Device" but not control
                        is_wpd_media_device = (
                            'WPD\\' in pnp_id and
                            has_vid and  # Must have VID to be real device
                            ('DEVICE' in name_upper or 'MEDIA' in name_upper or 'PHONE' in caption_upper) and
                            'CONTROL' not in name_upper
                        )
                        
                        # Check for MTP/WPD devices that might be phones (even without explicit VID in PNP ID)
                        # These are detected via their protocol - IMPORTANT for phone detection
                        is_likely_phone_via_protocol = (
                            ('MTP' in pnp_upper or 'WPD' in pnp_upper or 'PTP' in pnp_upper) and
                            'CONTROL' not in name_upper and
                            'ENUMERATOR' not in name_upper and
                            'CONVERTED' not in name_upper and
                            '\\' in pnp_id  # Has some structure (not just a name)
                        )
                        
                        # CRITICAL: USB Composite Device and APP Mode are OFTEN internal
                        # Exclude them unless they have VID/PID AND explicit external markers
                        if name_upper == 'USB COMPOSITE DEVICE':
                            # Only allow if it has VID/PID AND explicit external marker OR known external device pattern
                            if not (has_vid and ('EXTERNAL' in caption_upper or 'GAMING' in caption_upper or 'STEELSERIES' in caption_upper)):
                                log.debug(f"  Skipping internal USB Composite Device (no VID/PID or external marker): {name}")
                                continue  # Skip immediately, don't process further
                        
                        if name_upper == 'APP MODE':
                            # APP Mode is typically internal camera controller
                            # Only allow if it has VID/PID AND explicit external marker
                            if not (has_vid and ('EXTERNAL' in caption_upper)):
                                log.debug(f"  Skipping internal APP Mode (likely camera controller): {name}")
                                continue  # Skip immediately
                        
                        # STRICT: Only detect devices that are EXPLICITLY external
                        # REQUIREMENT: Must have VID/PID for ALL devices (except explicit mobile phones by name)
                        # Must meet ONE of these criteria:
                        # 1. Mobile phone (by name) - can work without VID if explicitly a phone
                        # 2. External storage (removable drives, USB sticks) - REQUIRES VID/PID
                        # 3. External peripherals (gaming keyboards/mice) - REQUIRES VID/PID
                        # 4. MTP/WPD devices with VID/PID (phones, cameras) - REQUIRES VID/PID
                        
                        is_external_device = False  # Default: NOT external
                        
                        # 1. Mobile phones (explicit names OR model codes with MTP/WPD) - prioritize phone detection
                        if is_mobile_phone:
                            # Phones can work without VID if they use MTP/WPD/PTP protocol
                            if has_vid:
                                is_external_device = True
                                log.debug(f"  ✓ External device (mobile phone with VID): {name}")
                            elif ('MTP' in pnp_upper or 'WPD' in pnp_upper or 'PTP' in pnp_upper):
                                # Phone via protocol (MTP/WPD) - allow even without VID for model codes
                                is_external_device = True
                                log.debug(f"  ✓ External device (mobile phone via protocol, model: {name}): {name}")
                            elif is_short_model_code:
                                # Short model code (like A059) with protocol detected - treat as phone
                                is_external_device = True
                                log.debug(f"  ✓ External device (phone model code via protocol): {name}")
                            else:
                                # Has phone name but no VID and no protocol - might be controller
                                log.debug(f"  Skipping potential phone (no VID and no protocol): {name}")
                        
                        # 2. MTP/WPD/PTP devices - MUST have VID/PID (excludes controllers)
                        elif is_portable_device:
                            if has_vid:
                                is_external_device = True
                                log.debug(f"  ✓ External device (MTP/WPD with VID): {name}")
                            else:
                                log.debug(f"  Skipping MTP/WPD device (no VID/PID - likely controller): {name}")
                        
                        # 3. USB Mass Storage (USBSTOR) - MUST have VID/PID
                        elif is_usb_storage:
                            # Already checks has_vid in is_usb_storage definition
                            is_external_device = True
                            log.debug(f"  ✓ External device (USB storage): {name}")
                        
                        # 4. Explicitly marked external storage - MUST have VID/PID
                        elif is_external_storage:
                            if has_vid:
                                is_external_device = True
                                log.debug(f"  ✓ External device (external storage): {name}")
                            else:
                                log.debug(f"  Skipping potential storage (no VID/PID): {name}")
                        
                        # 5. External peripherals (gaming keyboards/mice) - MUST have VID/PID
                        elif is_external_peripheral:
                            if has_vid:
                                is_external_device = True
                                log.debug(f"  ✓ External device (external peripheral): {name}")
                            else:
                                log.debug(f"  Skipping peripheral (no VID/PID): {name}")
                        
                        # 6. WPD media devices (phones/cameras) - MUST have VID/PID (checked in definition)
                        elif is_wpd_media_device:
                            is_external_device = True
                            log.debug(f"  ✓ External device (WPD media): {name}")
                        
                        # 7. MTP/WPD protocol devices (phones) - MUST have VID/PID OR explicit phone name
                        elif is_likely_phone_via_protocol:
                            if has_vid or is_mobile_phone:
                                is_external_device = True
                                log.debug(f"  ✓ External device (MTP/WPD protocol): {name}")
                            else:
                                # No VID and not explicitly a phone - likely controller, skip
                                log.debug(f"  Skipping MTP/WPD device (no VID/PID and not explicit phone): {name}")
                        
                        # FINAL CHECK: If still not external and has VID/PID, check if explicitly marked
                        if not is_external_device and has_vid:
                            # Only include if EXPLICITLY marked as removable/external AND not a controller/enumerator/hub
                            if (('REMOVABLE' in caption_upper or 'EXTERNAL' in caption_upper or 'PORTABLE' in name_upper) and
                                'CONTROLLER' not in name_upper and 
                                'ENUMERATOR' not in name_upper and
                                'HUB' not in name_upper and
                                'ROOT' not in name_upper):
                                is_external_device = True
                                log.debug(f"  ✓ External device (explicitly marked with VID/PID): {name}")
                            else:
                                log.debug(f"  Device has VID/PID but not explicitly external: {name}")
                        
                        if is_external_device and name and name.strip():
                            # Extract vendor/product IDs from PNP ID if available
                            vendor_id = None
                            product_id = None
                            serial_number = getattr(device, 'SerialNumber', None)
                            
                            # Parse PNP ID: USB\VID_XXXX&PID_YYYY... (better parsing)
                            try:
                                if '\\VID_' in pnp_id or 'VID_' in pnp_id:
                                    # Extract from patterns like: USB\VID_1234&PID_5678\...
                                    vid_match = re.search(r'VID_([A-F0-9]{4})', pnp_id, re.IGNORECASE)
                                    if vid_match:
                                        vendor_id = vid_match.group(1).upper()
                                    
                                    pid_match = re.search(r'PID_([A-F0-9]{4})', pnp_id, re.IGNORECASE)
                                    if pid_match:
                                        product_id = pid_match.group(1).upper()
                            except Exception as e:
                                log.debug(f"Error parsing VID/PID from {pnp_id}: {e}")
                            
                            device_info = {
                                'type': 'USB',
                                'name': name,
                                'path': getattr(device, 'DeviceID', None) or pnp_id,
                                'vendor_id': vendor_id,
                                'product_id': product_id,
                                'serial_number': serial_number
                            }
                            
                            # Avoid duplicates using hash
                            device_hash = _get_device_hash(device_info)
                            if device_hash not in detected_hashes:
                                devices.append(device_info)
                                detected_hashes.add(device_hash)
                                log.debug(f"  ✓ Detected: {name} (VID:{vendor_id}, PID:{product_id})")
                    except Exception as e:
                        log.debug(f"Error processing WMI device: {e}")
                        continue
                
                log.debug(f"Scanned {device_count} PnP devices, found {len([d for d in devices if _get_device_hash(d) in detected_hashes])} external devices")
            except Exception as e:
                log.warning(f"WMI Win32_PnPEntity error: {e}")
            
            # Method 1b: SKIPPED - Win32_USBControllerDevice catches too many internal controllers
            # All device detection is done via Win32_PnPEntity with strict filtering above
            pass
            
        except ImportError as e:
            log.warning(f"WMI library not available: {e}")
            log.warning("WARNING: For mobile phone detection, install WMI: pip install wmi")
            log.warning("Device detection will be limited without WMI - only removable drives will be detected")
        
        # Method 2: Fallback - Check removable drives (always check as backup)
        # This catches USB drives even without WMI
        try:
            import win32api
            import win32con
            import string
            log.debug("Checking removable drives...")
            drives = win32api.GetLogicalDriveStrings()
            drives = drives.split('\000')[:-1]
            
            for drive in drives:
                if drive:
                    try:
                        drive_type = win32api.GetDriveType(drive)
                        if drive_type == win32con.DRIVE_REMOVABLE:
                            try:
                                vol_info = win32api.GetVolumeInformation(drive)
                                drive_letter = drive.rstrip(':\\')
                                vol_name = vol_info[0] if vol_info[0] else f"Removable Drive {drive_letter}"
                            except:
                                drive_letter = drive.rstrip(':\\')
                                vol_name = f"Removable Drive {drive_letter}"
                            
                            device_info = {
                                'type': 'USB',
                                'name': vol_name,
                                'path': drive.rstrip('\\'),
                                'vendor_id': None,
                                'product_id': None,
                                'serial_number': None
                            }
                            
                            device_hash = _get_device_hash(device_info)
                            if device_hash not in detected_hashes:
                                devices.append(device_info)
                                detected_hashes.add(device_hash)
                                log.debug(f"  Removable Drive: {vol_name} ({drive})")
                    except Exception as e:
                        log.debug(f"Error reading drive {drive}: {e}")
        except ImportError:
            # No pywin32, use ctypes fallback
            try:
                import ctypes
                import string
                for letter in string.ascii_uppercase:
                    drive = f"{letter}:\\"
                    if os.path.exists(drive):
                        try:
                            if ctypes.windll.kernel32.GetDriveTypeW(drive) == 2:  # DRIVE_REMOVABLE
                                device_info = {
                                    'type': 'USB',
                                    'name': f"Removable Drive {letter}",
                                    'path': drive,
                                    'vendor_id': None,
                                    'product_id': None,
                                    'serial_number': None
                                }
                                device_hash = _get_device_hash(device_info)
                                if device_hash not in detected_hashes:
                                    devices.append(device_info)
                                    detected_hashes.add(device_hash)
                        except Exception:
                            pass
            except Exception as e:
                log.debug(f"Basic detection error: {e}")
        except Exception as e:
            log.debug(f"Drive enumeration error: {e}")
        
        log.info(f"Total external devices detected: {len(devices)}")
        return devices
    
    elif sys.platform.startswith('linux'):
        # Linux: check /sys/bus/usb/devices
        try:
            usb_path = '/sys/bus/usb/devices'
            if os.path.exists(usb_path):
                for device_file in os.listdir(usb_path):
                    if device_file.startswith('usb'):
                        continue
                    device_path = os.path.join(usb_path, device_file)
                    if not os.path.isdir(device_path):
                        continue
                    
                    # Read device info
                    name_file = os.path.join(device_path, 'product')
                    vendor_file = os.path.join(device_path, 'idVendor')
                    product_file = os.path.join(device_path, 'idProduct')
                    serial_file = os.path.join(device_path, 'serial')
                    
                    name = "USB Device"
                    vendor_id = None
                    product_id = None
                    serial = None
                    
                    if os.path.exists(name_file):
                        with open(name_file, 'r') as f:
                            name = f.read().strip()
                    if os.path.exists(vendor_file):
                        with open(vendor_file, 'r') as f:
                            vendor_id = f.read().strip()
                    if os.path.exists(product_file):
                        with open(product_file, 'r') as f:
                            product_id = f.read().strip()
                    if os.path.exists(serial_file):
                        with open(serial_file, 'r') as f:
                            serial = f.read().strip()
                    
                    if name or vendor_id:
                        devices.append({
                            'type': 'USB',
                            'name': name,
                            'path': device_path,
                            'vendor_id': vendor_id,
                            'product_id': product_id,
                            'serial_number': serial
                        })
        except Exception as e:
            log.debug(f"Error reading USB devices (Linux): {e}")
    
    elif sys.platform == 'darwin':
        # macOS: use system_profiler
        try:
            import subprocess
            result = subprocess.run(
                ['system_profiler', 'SPUSBDataType', '-xml'],
                capture_output=True,
                text=True,
                timeout=5
            )
            # Parse XML output (simplified - would need proper XML parsing)
            if result.returncode == 0:
                # Basic parsing - would need proper XML library
                devices.append({
                    'type': 'USB',
                    'name': 'USB Device (macOS)',
                    'path': None,
                    'vendor_id': None,
                    'product_id': None,
                    'serial_number': None
                })
        except Exception as e:
            log.debug(f"Error reading USB devices (macOS): {e}")
    
    return devices

def _get_device_hash(device):
    """Generate unique hash for device"""
    hash_str = f"{device.get('vendor_id', '')}-{device.get('product_id', '')}-{device.get('serial_number', '')}-{device.get('name', '')}"
    return hashlib.md5(hash_str.encode()).hexdigest()

def scan_devices():
    """Scan for connected devices and report to server - Real-time monitoring
    
    Always collects device information and stores in DB.
    If monitoring is enabled: Also checks for blocking and shows popup alerts.
    """
    # Always scan and collect device info (even if monitoring disabled)
    # This allows admins to view devices in UI and block/unblock later
    monitoring_enabled = is_device_monitoring_enabled()
    
    global _last_scan_time
    current_time = time.time()
    
    # REAL-TIME scanning - check every 1 second for immediate detection
    # Reduced from 2 seconds to 1 second for true real-time device detection
    scan_throttle = 1  # 1 second between scans for real-time detection
    if current_time - _last_scan_time < scan_throttle:
        return
    
    _last_scan_time = current_time
    log.info(f"🔍 Scanning for USB/external devices... (monitoring: {'ENABLED' if monitoring_enabled else 'DISABLED - collecting info only'})")
    
    try:
        # Get current devices - scans ALL USB ports
        current_devices = _get_usb_devices()
        current_device_hashes = set()
        
        if current_devices:
            log.info(f"📱 Found {len(current_devices)} external device(s) connected:")
            for dev in current_devices:
                log.info(f"   • {dev.get('name', 'Unknown')} (Type: {dev.get('type', 'USB')})")
        else:
            log.debug("No external devices found in this scan")
        
        for device in current_devices:
            device_hash = _get_device_hash(device)
            current_device_hashes.add(device_hash)
            device_name = device.get('name', 'Unknown Device')
            
            # Check if this is a new device
            if device_hash not in _seen_devices:
                log.info(f"🔌 NEW EXTERNAL DEVICE DETECTED: {device_name} (Type: {device.get('type', 'USB')}, Hash: {device_hash})")
                
                _seen_devices[device_hash] = {
                    'device': device,
                    'first_seen': datetime.utcnow(),
                    'last_seen': datetime.utcnow(),
                    'reported': False,
                    'alert_shown': False
                }
                
                # Always report new device connection to server (even if monitoring disabled)
                # This stores device info in DB for admin to view/block later
                is_blocked = False
                if monitoring_enabled:
                    # Only check block status if monitoring is enabled
                    is_blocked = is_device_blocked(device_hash, device_name)
                
                # Report to server - ALWAYS collect device info
                try:
                    report_device(device, device_hash, 'connected', is_blocked)
                    _seen_devices[device_hash]['reported'] = True
                    log.info(f"Device info synced to server: {device_name}")
                    
                    if monitoring_enabled and is_blocked:
                        log.warning(f"⚠️ Device {device_name} is BLOCKED - popup will be shown")
                except Exception as e:
                    log.warning(f"Failed to report device to server: {e}")
            else:
                # Update last seen
                if device_hash in _seen_devices:
                    _seen_devices[device_hash]['last_seen'] = datetime.utcnow()
        
        # Check for disconnected devices
        disconnected = []
        for device_hash, device_info in list(_seen_devices.items()):
            if device_hash not in current_device_hashes:
                # Device was disconnected
                device_name = device_info['device'].get('name', 'Unknown Device')
                log.info(f"🔌 DEVICE DISCONNECTED: {device_name} (Hash: {device_hash})")
                if device_info.get('reported', False):
                    try:
                        report_device(device_info['device'], device_hash, 'disconnected', False)
                    except Exception:
                        pass
                disconnected.append(device_hash)
        
        # Remove disconnected devices after delay
        for device_hash in disconnected:
            # Keep in seen devices for a bit in case it reconnects
            if device_hash in _seen_devices:
                last_seen_ts = _seen_devices[device_hash].get('last_seen')
                if last_seen_ts:
                    if isinstance(last_seen_ts, datetime):
                        if time.time() - last_seen_ts.timestamp() > 300:  # 5 minutes
                            del _seen_devices[device_hash]
                    else:
                        if time.time() - last_seen_ts > 300:
                            del _seen_devices[device_hash]
        
        # Check permissions and show alerts ONLY if monitoring is enabled
        if monitoring_enabled:
            for device in current_devices:
                device_hash = _get_device_hash(device)
                device_name = device.get('name', 'Unknown Device')
                
                # Check device permission (blocked, allowed, or None)
                permission = get_device_permission(device_hash, device_name)
                is_blocked = (permission == 'blocked')
                is_allowed = (permission == 'allowed')
                
                # Log permission status for debugging
                if permission is not None:
                    log.debug(f"Device permission check: {device_name} = {permission}")
                
                if is_blocked:
                    # Get or create device info
                    device_info = _seen_devices.get(device_hash, {})
                    if not device_info:
                        _seen_devices[device_hash] = {
                            'device': device,
                            'alert_shown': False,
                            'blocked_reported': False,
                            'last_alert_time': 0,
                            'last_seen': datetime.utcnow()
                        }
                        device_info = _seen_devices[device_hash]
                    
                    current_time = time.time()
                    last_alert_time = device_info.get('last_alert_time', 0)
                    
                    # Show popup alert REPEATEDLY while device is blocked and connected
                    # Show every 30 seconds to remind user
                    if current_time - last_alert_time >= _alert_interval:
                        log.warning(f"⚠️ BLOCKED EXTERNAL DEVICE DETECTED: {device_name} (Hash: {device_hash})")
                        
                        # Show popup alert IMMEDIATELY (periodically while blocked)
                        try:
                            log.info(f"Triggering popup alert for blocked device: {device_name}")
                            _show_blocked_device_alert(device_name)
                            log.info(f"Blocked device alert popup displayed for: {device_name} (periodic reminder)")
                            device_info['last_alert_time'] = current_time
                            _seen_devices[device_hash]['last_alert_time'] = current_time
                        except Exception as e:
                            log.error(f"Could not show blocked device alert: {e}")
                            import traceback
                            log.error(f"Popup error traceback: {traceback.format_exc()}")
                        
                        # Report blocked action (only once when first detected as blocked)
                        if not device_info.get('blocked_reported', False):
                            try:
                                report_device(device, device_hash, 'blocked', True)
                                _seen_devices[device_hash]['blocked_reported'] = True
                            except Exception as e:
                                log.warning(f"Could not report blocked device to server: {e}")
                    
                    # Attempt to block/eject device (platform specific) - CONTINUOUSLY try to block
                    # Try on every scan (every 1 second) to prevent access
                    try:
                        block_device_action(device)
                        log.debug(f"Attempted to block device: {device_name}")
                    except Exception as e:
                        log.debug(f"Could not block device physically: {e}")
                
                # Handle allowed devices (admin granted permission)
                elif is_allowed:
                    # Device is allowed - clear any previous blocked state
                    from permission import clear_device_cache
                    if device_hash in _seen_devices:
                        # Clear alert flag if device was previously blocked
                        if _seen_devices[device_hash].get('alert_shown', False):
                            log.info(f"✅ Device {device_name} is now ALLOWED - clearing blocked state")
                            _seen_devices[device_hash]['alert_shown'] = False
                            _seen_devices[device_hash]['last_alert_time'] = 0
                    # Clear permission cache to ensure fresh check
                    clear_device_cache(device_hash)
                    log.debug(f"Device {device_name} is allowed - no blocking needed")
                
                # If permission is None (not yet determined), log it for debugging
                elif permission is None:
                    log.debug(f"Device {device_name} permission not yet determined (no action)")
    
    except Exception as e:
        log.error(f"Error scanning devices: {e}")
        import traceback
        log.error(f"Scan devices traceback: {traceback.format_exc()}")

def report_device(device, device_hash, action, is_blocked):
    """Report device event to server"""
    try:
        payload = {
            'machine_id': MACHINE_ID,
            'user_id': USERNAME,
            'hostname': HOSTNAME,
            'device_type': device.get('type', 'USB'),
            'vendor_id': device.get('vendor_id'),
            'product_id': device.get('product_id'),
            'serial_number': device.get('serial_number'),
            'device_name': device.get('name', 'Unknown Device'),
            'device_path': device.get('path'),
            'device_hash': device_hash,
            'action': action,  # 'connected', 'disconnected', 'blocked'
            'is_blocked': is_blocked,
            'timestamp': datetime.utcnow().isoformat()
        }
        
        response = requests.post(
            DEVICE_API_URL,
            json=payload,
            timeout=10
        )
        
        if response.status_code == 200:
            log.debug(f"Device event reported: {action} - {device.get('name')}")
            return True
        else:
            log.warning(f"Failed to report device event: {response.status_code}")
            return False
    except Exception as e:
        log.debug(f"Error reporting device: {e}")
        return False

def block_device_action(device):
    """Attempt to block/eject device (platform specific) - called repeatedly to prevent access"""
    device_name = device.get('name', 'Unknown Device')
    device_path = device.get('path', '')
    device_hash = _get_device_hash(device)
    
    # Cache blocking attempts to avoid spamming logs
    if not hasattr(block_device_action, '_last_block_attempt'):
        block_device_action._last_block_attempt = {}
    
    current_time = time.time()
    last_attempt = block_device_action._last_block_attempt.get(device_hash, 0)
    
    # Only attempt blocking every 5 seconds per device (to avoid spam)
    if current_time - last_attempt < 5:
        return
    
    block_device_action._last_block_attempt[device_hash] = current_time
    
    if sys.platform == 'win32':
        try:
            # Method 1: Eject removable drives using PowerShell
            if device_path and len(device_path) == 1 and device_path.isalpha():
                # Drive letter like "E" - try to eject it
                drive_letter = device_path.upper()
                try:
                    import subprocess
                    result = subprocess.run(
                        ['powershell', '-Command', 
                         f'(New-Object -comObject Shell.Application).Namespace(17).ParseName("{drive_letter}:\\").InvokeVerb("Eject")'],
                        capture_output=True,
                        timeout=5,
                        creationflags=subprocess.CREATE_NO_WINDOW
                    )
                    if result.returncode == 0:
                        log.info(f"Successfully ejected device: {device_name} ({drive_letter}:\\)")
                    else:
                        log.debug(f"Eject command returned code {result.returncode} for {device_name}")
                except Exception as e:
                    log.debug(f"Could not eject drive {drive_letter}: {e}")
            
            # Method 2: Disable device via WMI (requires admin rights) - PRIMARY METHOD
            try:
                import wmi
                c = wmi.WMI()
                device_found = False
                
                # Find device by name or PNP path
                for pnp_device in c.Win32_PnPEntity():
                    pnp_id = getattr(pnp_device, 'PNPDeviceID', '') or ''
                    pnp_name = getattr(pnp_device, 'Name', '') or ''
                    pnp_status = getattr(pnp_device, 'Status', '') or ''
                    
                    # Match device by name (case-insensitive partial match) or PNP ID
                    if (device_name.upper() in pnp_name.upper() or 
                        (device_path and device_path.upper() in pnp_id.upper())):
                        device_found = True
                        try:
                            # Check if device is already disabled
                            if pnp_status.upper() == 'ERROR':
                                log.debug(f"Device {device_name} is already disabled")
                                break
                            
                            # Disable the device
                            pnp_device.Disable()
                            log.info(f"Successfully disabled device via WMI: {device_name} (Status: {pnp_status})")
                            break
                        except Exception as e:
                            # May require admin rights - log and continue to next method
                            error_msg = str(e)
                            if 'access' in error_msg.lower() or 'denied' in error_msg.lower():
                                log.warning(f"Device blocking requires admin rights for: {device_name}")
                            else:
                                log.debug(f"Could not disable device via WMI: {error_msg}")
                
                if not device_found:
                    log.debug(f"Device {device_name} not found in WMI for blocking")
                    
            except ImportError:
                log.debug("WMI not available for device blocking")
            except Exception as e:
                log.debug(f"WMI device blocking error: {e}")
            
            # Method 3: For mobile phones/MTP devices - try to remove from Windows Portable Devices
            if 'MTP' in str(device.get('path', '')).upper() or 'WPD' in str(device.get('path', '')).upper():
                try:
                    import subprocess
                    # Try to use devcon or pnputil to disable (requires admin)
                    log.debug(f"Attempting to block MTP/WPD device: {device_name}")
                except Exception as e:
                    log.debug(f"Could not block MTP/WPD device: {e}")
            
            # Method 4: Eject via diskpart (for storage devices)
            if 'storage' in device.get('type', '').lower() or 'disk' in device_name.lower() or device_path.isalpha():
                try:
                    import subprocess
                    # Try using diskpart to remove drive
                    if device_path and device_path.isalpha():
                        drive = f"{device_path}:"
                        # Use remove command via diskpart
                        diskpart_script = f"select volume {device_path}\nremove\n"
                        result = subprocess.run(
                            ['diskpart', '/s', '-'],
                            input=diskpart_script,
                            capture_output=True,
                            text=True,
                            timeout=5,
                            creationflags=subprocess.CREATE_NO_WINDOW
                        )
                        if 'removed' in result.stdout.lower():
                            log.info(f"Successfully removed drive via diskpart: {device_name}")
                except Exception as e:
                    log.debug(f"Could not block storage device via diskpart: {e}")
                    
        except Exception as e:
            log.warning(f"Error blocking device {device_name}: {e}")
    
    elif sys.platform.startswith('linux'):
        try:
            import subprocess
            # Unmount the device if it's mounted
            if device_path and os.path.exists(device_path):
                subprocess.run(['umount', device_path], timeout=5)
                log.info(f"Unmounted device: {device_name}")
        except Exception as e:
            log.debug(f"Could not unmount device: {e}")
    
    elif sys.platform == 'darwin':
        try:
            import subprocess
            # Eject on macOS
            if device_path:
                subprocess.run(['diskutil', 'eject', device_path], timeout=5)
                log.info(f"Ejected device: {device_name}")
        except Exception as e:
            log.debug(f"Could not eject device: {e}")

