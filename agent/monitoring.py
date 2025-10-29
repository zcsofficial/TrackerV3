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

def _get_usb_devices():
    """Detect USB devices - platform specific"""
    devices = []
    
    if sys.platform == 'win32':
        try:
            import win32api
            import win32con
            import win32file
            
            # Enumerate USB devices on Windows
            drives = win32api.GetLogicalDriveStrings()
            drives = drives.split('\000')[:-1]
            
            for drive in drives:
                if drive:
                    try:
                        drive_type = win32api.GetDriveType(drive)
                        if drive_type == win32con.DRIVE_REMOVABLE:
                            # Get volume info
                            vol_name = win32api.GetVolumeInformation(drive)[0]
                            if not vol_name:
                                vol_name = "Removable Drive"
                            
                            devices.append({
                                'type': 'USB',
                                'name': vol_name,
                                'path': drive.rstrip('\\'),
                                'vendor_id': None,
                                'product_id': None,
                                'serial_number': None
                            })
                    except Exception as e:
                        log.debug(f"Error reading drive {drive}: {e}")
        except ImportError:
            # Fallback: use WMI on Windows
            try:
                import wmi
                c = wmi.WMI()
                for usb in c.Win32_USBControllerDevice():
                    try:
                        device_info = usb.Dependent
                        devices.append({
                            'type': 'USB',
                            'name': getattr(device_info, 'Name', 'USB Device'),
                            'path': None,
                            'vendor_id': getattr(device_info, 'PNPDeviceID', '').split('\\')[0] if hasattr(device_info, 'PNPDeviceID') else None,
                            'product_id': None,
                            'serial_number': None
                        })
                    except Exception:
                        pass
            except ImportError:
                log.debug("WMI not available, using basic USB detection")
                # Very basic detection - just check for removable drives
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
    """Scan for connected devices and report to server"""
    # Check monitoring status dynamically
    if not is_device_monitoring_enabled():
        return
    
    global _last_scan_time
    current_time = time.time()
    
    # Throttle scans
    if current_time - _last_scan_time < DEVICE_CHECK_INTERVAL:
        return
    
    _last_scan_time = current_time
    
    try:
        # Get current devices
        current_devices = _get_usb_devices()
        current_device_hashes = set()
        
        for device in current_devices:
            device_hash = _get_device_hash(device)
            current_device_hashes.add(device_hash)
            
            # Check if this is a new device
            if device_hash not in _seen_devices:
                _seen_devices[device_hash] = {
                    'device': device,
                    'first_seen': datetime.utcnow(),
                    'last_seen': datetime.utcnow(),
                    'reported': False
                }
                
                # Check permission
                is_blocked = is_device_blocked(device_hash, device.get('name'))
                
                # Report to server
                try:
                    report_device(device, device_hash, 'connected', is_blocked)
                    _seen_devices[device_hash]['reported'] = True
                except Exception as e:
                    log.warning(f"Failed to report device: {e}")
            
            # Update last seen
            if device_hash in _seen_devices:
                _seen_devices[device_hash]['last_seen'] = datetime.utcnow()
        
        # Check for disconnected devices
        disconnected = []
        for device_hash, device_info in _seen_devices.items():
            if device_hash not in current_device_hashes:
                # Device was disconnected
                if device_info['reported']:
                    try:
                        report_device(device_info['device'], device_hash, 'disconnected', False)
                    except Exception:
                        pass
                disconnected.append(device_hash)
        
        # Remove disconnected devices after delay
        for device_hash in disconnected:
            # Keep in seen devices for a bit in case it reconnects
            if time.time() - _seen_devices[device_hash]['last_seen'].timestamp() > 300:  # 5 minutes
                del _seen_devices[device_hash]
        
        # Block devices if needed
        if is_device_monitoring_enabled():
            for device in current_devices:
                device_hash = _get_device_hash(device)
                if is_device_blocked(device_hash, device.get('name')):
                    log.warning(f"Blocked device detected: {device.get('name')} (Hash: {device_hash})")
                    # Attempt to block/eject device (platform specific)
                    try:
                        block_device_action(device)
                    except Exception as e:
                        log.debug(f"Could not block device: {e}")
    
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

