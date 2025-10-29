"""
Permission Module for TrackerV3 Agent
Handles device blocking and permission checking
"""
import requests
import logging
import sys
import os
import time

# Add parent directory to path for imports
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

try:
    from .config import PERMISSION_API_URL, MACHINE_ID, is_device_monitoring_enabled
except ImportError:
    from config import PERMISSION_API_URL, MACHINE_ID, is_device_monitoring_enabled

log = logging.getLogger('tracker_agent.permission')

# Cache for device permissions (avoid frequent API calls)
_permission_cache = {}
_cache_timeout = 10  # 10 seconds - reduced for faster permission updates from UI
_cache_timestamps = {}

def get_device_permission(device_hash, device_name=None):
    """
    Check if a device is allowed or blocked
    Returns: 'allowed', 'blocked', or None (not yet determined)
    """
    if not is_device_monitoring_enabled():
        return None  # Monitoring disabled, no permission check
    
    # Check cache first
    if device_hash in _permission_cache:
        if time.time() - _cache_timestamps.get(device_hash, 0) < _cache_timeout:
            return _permission_cache[device_hash]
    
    try:
        # Query server for device permission
        payload = {
            'machine_id': MACHINE_ID,
            'device_hash': device_hash,
            'device_name': device_name
        }
        response = requests.post(
            f"{PERMISSION_API_URL}?action=check",
            json=payload,
            timeout=5
        )
        
        if response.status_code == 200:
            result = response.json()
            permission = result.get('permission')  # 'allowed', 'blocked', or None
            old_permission = _permission_cache.get(device_hash)
            _permission_cache[device_hash] = permission
            _cache_timestamps[device_hash] = time.time()
            
            # Log permission status for debugging
            if old_permission != permission:
                log.info(f"Device permission updated: {device_name} (Hash: {device_hash}) - {old_permission} → {permission}")
            
            return permission
        else:
            log.warning(f"Permission API returned status {response.status_code} for device: {device_name}")
    except Exception as e:
        log.warning(f"Error checking device permission for {device_name}: {e}")
    
    return None  # Default: not determined yet

def is_device_allowed(device_hash, device_name=None):
    """Check if device is explicitly allowed"""
    permission = get_device_permission(device_hash, device_name)
    return permission == 'allowed'

def is_device_blocked(device_hash, device_name=None):
    """Check if device is explicitly blocked"""
    permission = get_device_permission(device_hash, device_name)
    return permission == 'blocked'

def clear_permission_cache():
    """Clear the permission cache"""
    global _permission_cache, _cache_timestamps
    _permission_cache = {}
    _cache_timestamps = {}

def clear_device_cache(device_hash):
    """Clear cache for a specific device (called when permission changes)"""
    global _permission_cache, _cache_timestamps
    if device_hash in _permission_cache:
        del _permission_cache[device_hash]
    if device_hash in _cache_timestamps:
        del _cache_timestamps[device_hash]

def block_device_local(device_hash):
    """Block device locally (add to cache)"""
    _permission_cache[device_hash] = 'blocked'
    _cache_timestamps[device_hash] = time.time()

def allow_device_local(device_hash):
    """Allow device locally (add to cache)"""
    _permission_cache[device_hash] = 'allowed'
    _cache_timestamps[device_hash] = time.time()

