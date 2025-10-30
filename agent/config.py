"""
Configuration Module for TrackerV3 Agent
Handles all configuration loading and management
"""
import os
import json
import socket

# Try to load config from config.json (created by installer)
APP_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CONFIG_PATH = os.path.join(APP_DIR, 'config.json')

# Initialize configuration
DATA_DIR = None
SERVER_BASE = os.environ.get('TRACKER_SERVER_BASE', 'http://localhost:8080')

if os.path.exists(CONFIG_PATH):
    try:
        with open(CONFIG_PATH, 'r') as f:
            config = json.load(f)
            DATA_DIR = config.get('data_dir', os.path.join(APP_DIR, 'data'))
            SERVER_BASE = config.get('server_base', SERVER_BASE)
    except Exception:
        pass

if DATA_DIR is None:
    DATA_DIR = os.path.join(APP_DIR, 'data')

# Create directories if they don't exist
os.makedirs(DATA_DIR, exist_ok=True)
os.makedirs(os.path.join(DATA_DIR, 'screenshots'), exist_ok=True)

# Paths
DB_PATH = os.path.join(DATA_DIR, 'agent.db')
SCREEN_DIR = os.path.join(DATA_DIR, 'screenshots')
LOG_PATH = os.path.join(DATA_DIR, 'agent.log')

# API endpoints
INGEST_URL = f"{SERVER_BASE}/api/ingest.php"
DEVICE_API_URL = f"{SERVER_BASE}/api/device.php"
PERMISSION_API_URL = f"{SERVER_BASE}/api/permissions.php"
WEBSITE_API_URL = f"{SERVER_BASE}/api/website.php"
APPLICATION_API_URL = f"{SERVER_BASE}/api/application.php"

# User and machine info
USERNAME = os.environ.get('USERNAME') or os.environ.get('USER') or 'unknown'
MACHINE_ID = os.environ.get('COMPUTERNAME') or socket.gethostname()
HOSTNAME = socket.gethostname()

# Agent settings (can be overridden by server)
VERBOSE = os.environ.get('TRACKER_VERBOSE', '1') not in ('0', 'false', 'False')
PARALLEL_WORKERS = int(os.environ.get('TRACKER_PARALLEL_WORKERS', '1'))
DELETE_SCREENSHOTS = os.environ.get('TRACKER_DELETE_SCREENSHOTS', '1') not in ('0', 'false', 'False')

# Device monitoring settings (can be overridden by server)
# Note: This is loaded dynamically and should be checked via os.environ in monitoring module
DEVICE_MONITORING_ENABLED = os.environ.get('TRACKER_DEVICE_MONITORING', '0') not in ('0', 'false', 'False')
DEVICE_CHECK_INTERVAL = int(os.environ.get('TRACKER_DEVICE_CHECK_INTERVAL', '2'))  # seconds (2s for real-time monitoring)

# Screenshot settings (can be overridden by server)
SCREENSHOTS_ENABLED = os.environ.get('TRACKER_SCREENSHOTS_ENABLED', '1') not in ('0', 'false', 'False')
SCREENSHOT_INTERVAL = int(os.environ.get('TRACKER_SCREENSHOT_INTERVAL', '300'))  # seconds (default 5 minutes)

# Website monitoring settings (can be overridden by server)
WEBSITE_MONITORING_ENABLED = os.environ.get('TRACKER_WEBSITE_MONITORING', '1') not in ('0', 'false', 'False')
WEBSITE_MONITORING_INTERVAL = int(os.environ.get('TRACKER_WEBSITE_MONITORING_INTERVAL', '1'))  # seconds (1s for real-time)

# Application monitoring settings (can be overridden by server)
APPLICATION_MONITORING_ENABLED = os.environ.get('TRACKER_APPLICATION_MONITORING', '1') not in ('0', 'false', 'False')
APPLICATION_MONITORING_INTERVAL = int(os.environ.get('TRACKER_APPLICATION_MONITORING_INTERVAL', '2'))  # seconds (2s for real-time)

def is_device_monitoring_enabled():
    """Check if device monitoring is enabled (reads from environment)"""
    return os.environ.get('TRACKER_DEVICE_MONITORING', '0') not in ('0', 'false', 'False')

def is_screenshots_enabled():
    """Check if screenshots are enabled (reads from environment)"""
    return os.environ.get('TRACKER_SCREENSHOTS_ENABLED', '1') not in ('0', 'false', 'False')

def get_screenshot_interval():
    """Get screenshot interval in seconds (reads from environment)"""
    return int(os.environ.get('TRACKER_SCREENSHOT_INTERVAL', '300'))

def is_website_monitoring_enabled():
    """Check if website monitoring is enabled (reads from environment)"""
    return os.environ.get('TRACKER_WEBSITE_MONITORING', '1') not in ('0', 'false', 'False')

def get_website_monitoring_interval():
    """Get website monitoring interval in seconds (reads from environment)"""
    return int(os.environ.get('TRACKER_WEBSITE_MONITORING_INTERVAL', '1'))

def get_application_monitoring_enabled():
    """Check if application monitoring is enabled (reads from environment)"""
    return os.environ.get('TRACKER_APPLICATION_MONITORING', '1') not in ('0', 'false', 'False')

def get_application_monitoring_interval():
    """Get application monitoring interval in seconds (reads from environment)"""
    return int(os.environ.get('TRACKER_APPLICATION_MONITORING_INTERVAL', '2'))

def update_from_server_response(server_response):
    """Update configuration from server response and log changes"""
    if not isinstance(server_response, dict):
        return
    
    import logging
    log = logging.getLogger('tracker_agent.config')
    settings_updated = []
    
    # Update sync interval
    if 'sync_interval_seconds' in server_response:
        try:
            interval = int(server_response['sync_interval_seconds'])
            if interval >= 15:
                old_val = os.environ.get('TRACKER_SYNC_INTERVAL', '10')
                os.environ['TRACKER_SYNC_INTERVAL'] = str(interval)
                if old_val != str(interval):
                    settings_updated.append(f"sync_interval={old_val}→{interval}s")
        except Exception:
            pass
    
    # Update parallel workers
    if 'parallel_sync_workers' in server_response:
        try:
            workers = int(server_response['parallel_sync_workers'])
            if 1 <= workers <= 10:
                old_val = os.environ.get('TRACKER_PARALLEL_WORKERS', '1')
                os.environ['TRACKER_PARALLEL_WORKERS'] = str(workers)
                if old_val != str(workers):
                    settings_updated.append(f"parallel_workers={old_val}→{workers}")
        except Exception:
            pass
    
    # Update screenshot deletion
    if 'delete_screenshots_after_sync' in server_response:
        val = server_response['delete_screenshots_after_sync']
        old_val = os.environ.get('TRACKER_DELETE_SCREENSHOTS', '1')
        new_val = '1' if val else '0'
        os.environ['TRACKER_DELETE_SCREENSHOTS'] = new_val
        if old_val != new_val:
            settings_updated.append(f"delete_screenshots={old_val}→{new_val}")
    
    # Update device monitoring
    if 'device_monitoring_enabled' in server_response:
        val = server_response['device_monitoring_enabled']
        old_val = os.environ.get('TRACKER_DEVICE_MONITORING', '0')
        new_val = '1' if val else '0'
        os.environ['TRACKER_DEVICE_MONITORING'] = new_val
        if old_val != new_val:
            status = 'ENABLED' if val else 'DISABLED'
            settings_updated.append(f"device_monitoring={old_val}→{new_val} ({status})")
    
    # Update screenshot settings
    if 'screenshots_enabled' in server_response:
        val = server_response['screenshots_enabled']
        old_val = os.environ.get('TRACKER_SCREENSHOTS_ENABLED', '1')
        new_val = '1' if val else '0'
        os.environ['TRACKER_SCREENSHOTS_ENABLED'] = new_val
        if old_val != new_val:
            status = 'ENABLED' if val else 'DISABLED'
            settings_updated.append(f"screenshots={old_val}→{new_val} ({status})")
    
    if 'screenshot_interval_seconds' in server_response:
        try:
            interval = int(server_response['screenshot_interval_seconds'])
            if interval >= 60:  # Minimum 60 seconds
                old_val = os.environ.get('TRACKER_SCREENSHOT_INTERVAL', '300')
                os.environ['TRACKER_SCREENSHOT_INTERVAL'] = str(interval)
                if old_val != str(interval):
                    settings_updated.append(f"screenshot_interval={old_val}→{interval}s ({interval//60}min)")
        except Exception:
            pass
    
    # Update website monitoring
    if 'website_monitoring_enabled' in server_response:
        val = server_response['website_monitoring_enabled']
        old_val = os.environ.get('TRACKER_WEBSITE_MONITORING', '1')
        new_val = '1' if val else '0'
        os.environ['TRACKER_WEBSITE_MONITORING'] = new_val
        if old_val != new_val:
            status = 'ENABLED' if val else 'DISABLED'
            settings_updated.append(f"website_monitoring={old_val}→{new_val} ({status})")
    
    if 'website_monitoring_interval_seconds' in server_response:
        try:
            interval = int(server_response['website_monitoring_interval_seconds'])
            if interval >= 1:  # Minimum 1 second (real-time)
                old_val = os.environ.get('TRACKER_WEBSITE_MONITORING_INTERVAL', '1')
                os.environ['TRACKER_WEBSITE_MONITORING_INTERVAL'] = str(interval)
                if old_val != str(interval):
                    settings_updated.append(f"website_monitoring_interval={old_val}→{interval}s ({'real-time' if interval <= 1 else 'normal'})")
        except Exception:
            pass
    
    # Update application monitoring
    if 'application_monitoring_enabled' in server_response:
        val = server_response['application_monitoring_enabled']
        old_val = os.environ.get('TRACKER_APPLICATION_MONITORING', '1')
        new_val = '1' if val else '0'
        os.environ['TRACKER_APPLICATION_MONITORING'] = new_val
        if old_val != new_val:
            status = 'ENABLED' if val else 'DISABLED'
            settings_updated.append(f"application_monitoring={old_val}→{new_val} ({status})")
    
    if 'application_monitoring_interval_seconds' in server_response:
        try:
            interval = int(server_response['application_monitoring_interval_seconds'])
            if interval >= 1:  # Minimum 1 second
                old_val = os.environ.get('TRACKER_APPLICATION_MONITORING_INTERVAL', '2')
                os.environ['TRACKER_APPLICATION_MONITORING_INTERVAL'] = str(interval)
                if old_val != str(interval):
                    settings_updated.append(f"application_monitoring_interval={old_val}→{interval}s")
        except Exception:
            pass
    
    # Log all setting changes
    if settings_updated:
        log.info('Settings updated from server: %s', ', '.join(settings_updated))
    
    # Log current active settings periodically (first time or on change)
    current_settings = {
        'sync_interval': os.environ.get('TRACKER_SYNC_INTERVAL', '10'),
        'parallel_workers': os.environ.get('TRACKER_PARALLEL_WORKERS', '1'),
        'delete_screenshots': os.environ.get('TRACKER_DELETE_SCREENSHOTS', '1'),
        'device_monitoring': os.environ.get('TRACKER_DEVICE_MONITORING', '0'),
        'screenshots_enabled': os.environ.get('TRACKER_SCREENSHOTS_ENABLED', '1'),
        'screenshot_interval': os.environ.get('TRACKER_SCREENSHOT_INTERVAL', '300'),
        'website_monitoring': os.environ.get('TRACKER_WEBSITE_MONITORING', '1'),
        'website_monitoring_interval': os.environ.get('TRACKER_WEBSITE_MONITORING_INTERVAL', '1')
    }
    if settings_updated:
        log.info('Current active settings: sync_interval=%ss, parallel_workers=%s, delete_screenshots=%s, device_monitoring=%s, screenshots_enabled=%s, screenshot_interval=%ss, website_monitoring=%s, website_monitoring_interval=%ss',
                 current_settings['sync_interval'], current_settings['parallel_workers'],
                 current_settings['delete_screenshots'], current_settings['device_monitoring'],
                 current_settings['screenshots_enabled'], current_settings['screenshot_interval'],
                 current_settings['website_monitoring'], current_settings['website_monitoring_interval'])

