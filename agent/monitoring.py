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
from datetime import datetime

# Add parent directory to path for imports
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

try:
    from .config import (
        DEVICE_CHECK_INTERVAL, DEVICE_API_URL, MACHINE_ID, USERNAME, HOSTNAME, is_device_monitoring_enabled
    )
    from .permission import is_device_blocked, is_device_allowed
except ImportError:
    from config import (
        DEVICE_CHECK_INTERVAL, DEVICE_API_URL, MACHINE_ID, USERNAME, HOSTNAME, is_device_monitoring_enabled
    )
    from permission import is_device_blocked, is_device_allowed

log = logging.getLogger('tracker_agent.monitoring')

# Track previously seen devices
_seen_devices = {}
_last_scan_time = 0

def _show_blocked_device_alert(device_name):
    """Show popup alert when device is blocked"""
    if sys.platform == 'win32':
        try:
            import ctypes
            ctypes.windll.user32.MessageBoxW(
                0,
                f"External device '{device_name}' is blocked by security policy.\n\nPlease contact your administrator to request device access.",
                "Device Blocked - TrackerV3 Agent",
                0x10 | 0x0  # MB_ICONSTOP | MB_OK
            )
            log.info(f"Blocked device alert shown for: {device_name}")
        except Exception as e:
            log.debug(f"Could not show alert: {e}")
    elif sys.platform.startswith('linux'):
        try:
            import subprocess
            subprocess.run([
                'zenity', '--error',
                '--title=Device Blocked - TrackerV3 Agent',
                f'--text=External device "{device_name}" is blocked by security policy.\n\nPlease contact your administrator to request device access.'
            ], timeout=5)
        except Exception:
            try:
                import subprocess
                subprocess.run([
                    'notify-send',
                    'Device Blocked - TrackerV3 Agent',
                    f'External device "{device_name}" is blocked by security policy. Contact administrator.'
                ], timeout=5)
            except Exception:
                pass
    elif sys.platform == 'darwin':
        try:
            import subprocess
            subprocess.run([
                'osascript', '-e',
                f'display dialog "External device \'{device_name}\' is blocked by security policy.\\n\\nPlease contact your administrator to request device access." with title "Device Blocked - TrackerV3 Agent" buttons {{"OK"}} default button "OK" with icon stop'
            ], timeout=5)
        except Exception:
            pass

def _get_usb_devices():
    """Detect USB devices - platform specific with improved detection"""
    devices = []
    
    if sys.platform == 'win32':
        # Use WMI for comprehensive device detection (mobile phones, USB drives, etc.)
        try:
            import wmi
            c = wmi.WMI()
            
            # Method 1: Use Win32_PnPEntity for ALL USB-connected devices (comprehensive)
            # This catches phones, storage, keyboards, mice, printers, etc. on ALL USB ports
            try:
                # Scan ALL PnP devices for USB connections (comprehensive)
                for device in c.Win32_PnPEntity():
                    try:
                        # Check if device is USB-related
                        pnp_id = getattr(device, 'PNPDeviceID', '') or ''
                        caption = getattr(device, 'Caption', '') or ''
                        name = getattr(device, 'Name', '') or caption
                        
                        # Filter for USB devices - capture ALL USB-connected devices on ALL ports
                        # Check PNP Device ID for USB connection indicators
                        pnp_upper = pnp_id.upper()
                        
                        # Direct USB connection check (catches devices on any USB port)
                        is_usb = (
                            'USB\\' in pnp_id or  # USB prefix in PNP ID
                            pnp_id.startswith('USB\\') or  # Starts with USB
                            'USBSTOR' in pnp_upper or  # USB Mass Storage
                            'USB\\VID_' in pnp_id or  # USB with Vendor ID
                            'USB\\ROOT' in pnp_upper or  # USB Root Hub
                            'USB\\CLASS_' in pnp_upper or  # USB Class device
                            'COMPOSITE' in pnp_upper  # Composite USB device
                        )
                        
                        # Check for USB device classes and protocols
                        is_usb_device_class = (
                            'MTP' in pnp_upper or  # Media Transfer Protocol (phones, cameras)
                            'PTP' in pnp_upper or  # Picture Transfer Protocol
                            'WPD' in pnp_upper or  # Windows Portable Device
                            'UMB' in pnp_upper  # USB Mass Storage Bulk-Only
                        )
                        
                        # Check for external storage/removable media
                        is_external_storage = (
                            'REMOVABLE' in caption.upper() or
                            'PORTABLE' in caption.upper() or
                            'STORAGE' in caption.upper() or
                            ('DISK' in caption.upper() and 'DRIVE' in caption.upper()) or
                            'MEDIA' in caption.upper() or
                            'FLASH' in caption.upper() or
                            'MEMORY' in caption.upper()
                        )
                        
                        # Check DeviceID path for USB connection (another indicator)
                        device_id = getattr(device, 'DeviceID', '') or ''
                        is_usb_in_device_id = 'USB' in device_id.upper()
                        
                        # Final determination: External device connected via USB (any port)
                        is_external_device = (
                            is_usb or
                            is_usb_device_class or
                            is_usb_in_device_id or
                            is_external_storage
                        )
                        
                        if is_external_device and name:
                            # Extract vendor/product IDs from PNP ID if available
                            vendor_id = None
                            product_id = None
                            serial_number = getattr(device, 'SerialNumber', None)
                            
                            # Parse PNP ID: USB\VID_XXXX&PID_YYYY...
                            if '\\VID_' in pnp_id:
                                parts = pnp_id.split('\\')
                                for part in parts:
                                    if part.startswith('VID_'):
                                        vendor_id = part.split('&')[0].replace('VID_', '')
                                        if 'PID_' in part:
                                            product_id = part.split('PID_')[1].split('&')[0].split('\\')[0]
                            
                            device_info = {
                                'type': 'USB',
                                'name': name,
                                'path': getattr(device, 'DeviceID', None) or pnp_id,
                                'vendor_id': vendor_id,
                                'product_id': product_id,
                                'serial_number': serial_number
                            }
                            
                            # Avoid duplicates
                            device_hash = _get_device_hash(device_info)
                            if not any(_get_device_hash(d) == device_hash for d in devices):
                                devices.append(device_info)
                    except Exception as e:
                        log.debug(f"Error processing WMI device: {e}")
                        continue
            except Exception as e:
                log.debug(f"WMI Win32_PnPEntity error: {e}")
            
            # Method 2: Also check for removable drives
            try:
                import win32api
                import win32con
                drives = win32api.GetLogicalDriveStrings()
                drives = drives.split('\000')[:-1]
                
                for drive in drives:
                    if drive:
                        try:
                            drive_type = win32api.GetDriveType(drive)
                            if drive_type == win32con.DRIVE_REMOVABLE:
                                vol_name = win32api.GetVolumeInformation(drive)[0]
                                if not vol_name:
                                    drive_letter = drive.rstrip(':\\')
                                    vol_name = f"Removable Drive ({drive_letter})"
                                
                                device_info = {
                                    'type': 'USB',
                                    'name': vol_name,
                                    'path': drive.rstrip('\\'),
                                    'vendor_id': None,
                                    'product_id': None,
                                    'serial_number': None
                                }
                                
                                # Avoid duplicates
                                device_hash = _get_device_hash(device_info)
                                if not any(_get_device_hash(d) == device_hash for d in devices):
                                    devices.append(device_info)
                        except Exception as e:
                            log.debug(f"Error reading drive {drive}: {e}")
            except ImportError:
                pass
            except Exception as e:
                log.debug(f"Error enumerating drives: {e}")
                
        except ImportError:
            # Fallback: basic detection without WMI
            log.debug("WMI not available, using basic USB detection")
            try:
                import string
                import ctypes
                for letter in string.ascii_uppercase:
                    drive = f"{letter}:\\"
                    if os.path.exists(drive):
                        try:
                            if ctypes.windll.kernel32.GetDriveTypeW(drive) == 2:  # DRIVE_REMOVABLE
                                devices.append({
                                    'type': 'USB',
                                    'name': f"Removable Drive {letter}",
                                    'path': drive,
                                    'vendor_id': None,
                                    'product_id': None,
                                    'serial_number': None
                                })
                        except Exception:
                            pass
            except Exception as e:
                log.debug(f"Basic detection error: {e}")
    
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
    
    # Real-time scanning - check every 2 seconds for immediate detection
    # DEVICE_CHECK_INTERVAL is 5 seconds, but we scan more frequently for real-time
    scan_throttle = max(2, DEVICE_CHECK_INTERVAL - 3)  # Minimum 2 seconds between scans
    if current_time - _last_scan_time < scan_throttle:
        return
    
    _last_scan_time = current_time
    log.debug(f"Scanning for USB/external devices... (monitoring: {'ENABLED' if monitoring_enabled else 'DISABLED - collecting info only'})")
    
    try:
        # Get current devices - scans ALL USB ports
        current_devices = _get_usb_devices()
        current_device_hashes = set()
        
        if current_devices:
            log.debug(f"Found {len(current_devices)} external device(s)")
        
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
        
        # Block devices and show alerts ONLY if monitoring is enabled
        if monitoring_enabled:
            for device in current_devices:
                device_hash = _get_device_hash(device)
                device_name = device.get('name', 'Unknown Device')
                
                # Check if device is blocked
                is_blocked = is_device_blocked(device_hash, device_name)
                
                if is_blocked:
                    # Check if we've already shown alert for this device in this session
                    device_info = _seen_devices.get(device_hash, {})
                    if not device_info.get('alert_shown', False):
                        log.warning(f"⚠️ BLOCKED EXTERNAL DEVICE DETECTED: {device_name} (Hash: {device_hash})")
                        
                        # Show popup alert to user IMMEDIATELY (only once per device connection)
                        try:
                            _show_blocked_device_alert(device_name)
                            log.info(f"Blocked device alert popup displayed for: {device_name}")
                        except Exception as e:
                            log.error(f"Could not show blocked device alert: {e}")
                        
                        # Mark that alert was shown
                        if device_hash in _seen_devices:
                            _seen_devices[device_hash]['alert_shown'] = True
                        else:
                            _seen_devices[device_hash] = {'alert_shown': True, 'device': device}
                        
                        # Report blocked action (only once when first detected as blocked)
                        if not device_info.get('blocked_reported', False):
                            try:
                                report_device(device, device_hash, 'blocked', True)
                                if device_hash in _seen_devices:
                                    _seen_devices[device_hash]['blocked_reported'] = True
                            except Exception as e:
                                log.warning(f"Could not report blocked device to server: {e}")
                    
                    # Attempt to block/eject device (platform specific)
                    try:
                        block_device_action(device)
                    except Exception as e:
                        log.debug(f"Could not block device physically: {e}")
    
    except Exception as e:
        log.error(f"Error scanning devices: {e}")

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
    """Attempt to block/eject device (platform specific)"""
    # This is a placeholder - actual blocking would require:
    # - Windows: disable device via registry or WMI
    # - Linux: unmount and remove via udev rules
    # - macOS: eject via diskutil
    log.info(f"Device blocking requested for: {device.get('name')}")
    # Implementation would go here based on platform

