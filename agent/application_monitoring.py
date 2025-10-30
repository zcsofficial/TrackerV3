"""
Application Monitoring Module for TrackerV3 Agent
Tracks running applications and their usage (ActivTrak-like)
"""
import os
import sys
import time
import logging
import requests
import threading
from datetime import datetime, timedelta
from collections import defaultdict

# Add parent directory to path for imports
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

try:
    from .config import (
        MACHINE_ID, USERNAME, HOSTNAME, 
        get_application_monitoring_enabled, get_application_monitoring_interval,
        APPLICATION_API_URL
    )
except ImportError:
    from config import (
        MACHINE_ID, USERNAME, HOSTNAME,
        get_application_monitoring_enabled, get_application_monitoring_interval,
        APPLICATION_API_URL
    )

log = logging.getLogger('tracker_agent.application_monitoring')

# Track active applications
_active_applications = {}
_last_scan_time = 0
_scan_throttle = 2  # Minimum seconds between scans
_process_cache = {}

def _get_active_window_info():
    """Get information about the currently active window"""
    if sys.platform != 'win32':
        return None, None, None
    
    try:
        import win32gui
        import win32process
        
        hwnd = win32gui.GetForegroundWindow()
        if not hwnd:
            return None, None, None
        
        window_title = win32gui.GetWindowText(hwnd)
        _, pid = win32process.GetWindowThreadProcessId(hwnd)
        
        return window_title, pid, hwnd
    except Exception as e:
        log.debug(f"Error getting active window: {e}")
        return None, None, None

def _get_process_info(pid):
    """Get process information and identify application"""
    if pid in _process_cache:
        return _process_cache[pid]
    
    try:
        import psutil
        
        proc = psutil.Process(pid)
        
        process_name = proc.name()
        exe_path = proc.exe() if hasattr(proc, 'exe') else None
        
        # Get application name (try to get friendly name)
        app_name = _get_friendly_app_name(process_name, exe_path)
        
        # Determine if application is productive (based on category)
        is_productive = _is_productive_app(process_name, app_name)
        
        info = {
            'pid': pid,
            'process_name': process_name,
            'exe_path': exe_path,
            'app_name': app_name,
            'is_productive': is_productive
        }
        
        _process_cache[pid] = info
        return info
    
    except (psutil.NoSuchProcess, psutil.AccessDenied):
        return None
    except Exception as e:
        log.debug(f"Error getting process info for PID {pid}: {e}")
        return None

def _get_friendly_app_name(process_name, exe_path=None):
    """Get friendly application name from process"""
    # Remove .exe extension
    name = process_name.lower().replace('.exe', '')
    
    # Mapping of common processes to friendly names
    app_mapping = {
        'chrome': 'Google Chrome',
        'msedge': 'Microsoft Edge',
        'firefox': 'Mozilla Firefox',
        'opera': 'Opera Browser',
        'brave': 'Brave Browser',
        'code': 'Visual Studio Code',
        'notepad++': 'Notepad++',
        'notepad': 'Notepad',
        'word': 'Microsoft Word',
        'excel': 'Microsoft Excel',
        'powerpnt': 'Microsoft PowerPoint',
        'outlook': 'Microsoft Outlook',
        'teams': 'Microsoft Teams',
        'zoom': 'Zoom',
        'discord': 'Discord',
        'slack': 'Slack',
        'spotify': 'Spotify',
        'vscode': 'Visual Studio Code',
        'devenv': 'Visual Studio',
        'winrar': 'WinRAR',
        '7zfm': '7-Zip',
        'acrobat': 'Adobe Acrobat',
        'acrord32': 'Adobe Acrobat Reader',
        'photoshop': 'Adobe Photoshop',
        'illustrator': 'Adobe Illustrator',
        'cursor': 'Cursor',
    }
    
    # Check mapping
    for key, friendly_name in app_mapping.items():
        if key in name:
            return friendly_name
    
    # Try to extract from exe path
    if exe_path:
        try:
            # Get folder name
            folder = os.path.basename(os.path.dirname(exe_path))
            if folder:
                # Capitalize properly
                words = folder.replace('\\', ' ').replace('_', ' ').split()
                if words:
                    return ' '.join(w.capitalize() for w in words[:3])
        except Exception:
            pass
    
    # Default: capitalize process name
    return process_name.replace('.exe', '').replace('_', ' ').title()

def _is_productive_app(process_name, app_name):
    """Determine if application is productive based on name"""
    process_lower = process_name.lower()
    app_lower = app_name.lower()
    
    # Productive applications
    productive_keywords = [
        'code', 'visual studio', 'cursor', 'notepad', 'word', 'excel', 'powerpoint',
        'outlook', 'email', 'calendar', 'office', 'teams', 'zoom', 'meeting',
        'chrome', 'edge', 'firefox', 'browser', 'pdf', 'acrobat', 'adobe',
        'excel', 'powerpoint', 'word', 'onenote', 'office',
        'slack', 'trello', 'asana', 'project', 'task',
        'photoshop', 'illustrator', 'design', 'cad', 'sketch',
        'excel', 'calculator', 'paint', 'snipping', 'cmd', 'powershell', 'terminal'
    ]
    
    # Unproductive applications
    unproductive_keywords = [
        'game', 'steam', 'epic', 'fortnite', 'minecraft', 'roblox',
        'spotify', 'music', 'media player', 'vlc', 'itunes',
        'discord', 'telegram', 'whatsapp', 'messenger',
        'youtube', 'netflix', 'hulu', 'twitch',
        'facebook', 'instagram', 'twitter', 'tiktok', 'snapchat'
    ]
    
    # Check unproductive first
    for keyword in unproductive_keywords:
        if keyword in process_lower or keyword in app_lower:
            return False
    
    # Check productive
    for keyword in productive_keywords:
        if keyword in process_lower or keyword in app_lower:
            return True
    
    # Default: assume productive (can be reclassified later)
    return True

def scan_applications():
    """Scan for active applications and report usage"""
    if not get_application_monitoring_enabled():
        return
    
    global _last_scan_time, _active_applications
    
    current_time = time.time()
    monitoring_interval = get_application_monitoring_interval()
    
    # Throttle scans
    if monitoring_interval > 0 and (current_time - _last_scan_time < _scan_throttle):
        return
    
    _last_scan_time = current_time
    
    try:
        window_title, pid, hwnd = _get_active_window_info()
        
        if not pid:
            return
        
        # Get process info
        proc_info = _get_process_info(pid)
        if not proc_info:
            return
        
        app_name = proc_info['app_name']
        process_name = proc_info['process_name']
        is_productive = proc_info.get('is_productive', True)
        
        # Create unique key for this application session
        app_key = f"{process_name}:{pid}"
        
        # Check if this is a new application or same one
        if app_key not in _active_applications:
            # New application started
            log.info(f"📱 NEW APPLICATION: {app_name} ({process_name})")
            _active_applications[app_key] = {
                'app_name': app_name,
                'process_name': process_name,
                'pid': pid,
                'window_title': window_title,
                'start_time': datetime.utcnow(),
                'is_productive': is_productive,
                'reported': False
            }
            
            # Report immediately
            try:
                report_application_usage(
                    app_name, process_name, window_title, 
                    proc_info.get('exe_path'), is_productive
                )
                _active_applications[app_key]['reported'] = True
            except Exception as e:
                log.warning(f"Failed to report application usage: {e}")
        else:
            # Update existing application
            _active_applications[app_key]['window_title'] = window_title
            _active_applications[app_key]['last_update'] = datetime.utcnow()
        
        # Check for closed applications (apps that were active but are no longer)
        closed_apps = []
        for key in list(_active_applications.keys()):
            if key != app_key:
                app_info = _active_applications[key]
                # Check if process still exists
                try:
                    import psutil
                    psutil.Process(app_info['pid'])
                except (psutil.NoSuchProcess, psutil.AccessDenied):
                    # Process closed
                    closed_apps.append(key)
        
        # Report closed applications
        for key in closed_apps:
            app_info = _active_applications.pop(key)
            start_time = app_info['start_time']
            end_time = datetime.utcnow()
            duration = int((end_time - start_time).total_seconds())
            
            log.info(f"🔒 APPLICATION CLOSED: {app_info['app_name']} (Duration: {duration}s)")
            
            try:
                update_application_duration(
                    app_info['app_name'],
                    app_info['process_name'],
                    duration,
                    end_time
                )
            except Exception as e:
                log.warning(f"Failed to update application duration: {e}")
        
        # Clean up cache periodically
        if len(_process_cache) > 100:
            # Keep only recent processes
            _process_cache.clear()
    
    except Exception as e:
        log.error(f"Error scanning applications: {e}")
        import traceback
        log.error(f"Traceback: {traceback.format_exc()}")

def report_application_usage(app_name, process_name, window_title=None, exe_path=None, is_productive=True):
    """Report application usage to server"""
    try:
        payload = {
            'machine_id': MACHINE_ID,
            'user_id': USERNAME,
            'application_name': app_name,
            'process_name': process_name,
            'window_title': window_title or '',
            'executable_path': exe_path or '',
            'session_start': datetime.utcnow().strftime('%Y-%m-%d %H:%M:%S'),
            'is_productive': 1 if is_productive else 0
        }
        
        response = requests.post(
            APPLICATION_API_URL,
            json=payload,
            timeout=10
        )
        
        if response.status_code == 200:
            result = response.json()
            if result.get('is_blocked'):
                log.warning(f"⚠️ BLOCKED APPLICATION ACCESSED: {app_name}")
                _show_blocked_app_alert(app_name)
            log.debug(f"Application usage reported: {app_name}")
            return True
        else:
            log.warning(f"Failed to report application usage: Status {response.status_code}")
            return False
    except Exception as e:
        log.debug(f"Error reporting application usage: {e}")
        return False

def update_application_duration(app_name, process_name, duration_seconds, end_time):
    """Update application usage duration on server"""
    try:
        payload = {
            'action': 'update_duration',
            'machine_id': MACHINE_ID,
            'user_id': USERNAME,
            'application_name': app_name,
            'process_name': process_name,
            'duration_seconds': duration_seconds,
            'session_end': end_time.strftime('%Y-%m-%d %H:%M:%S')
        }
        
        response = requests.post(
            APPLICATION_API_URL,
            json=payload,
            timeout=10
        )
        
        if response.status_code == 200:
            log.debug(f"Application duration updated: {app_name} ({duration_seconds}s)")
            return True
        else:
            log.debug(f"Failed to update application duration: Status {response.status_code}")
            return False
    except Exception as e:
        log.debug(f"Error updating application duration: {e}")
        return False

def _show_blocked_app_alert(app_name):
    """Show popup alert when blocked application is accessed - NON-BLOCKING"""
    def _show_alert_thread():
        if sys.platform == 'win32':
            try:
                import ctypes
                result = ctypes.windll.user32.MessageBoxW(
                    0,
                    f"Application '{app_name}' is blocked by security policy.\n\nAccess denied.\n\nAgent continues tracking in background.",
                    "Application Blocked - TrackerV3 Agent",
                    0x10 | 0x0  # MB_ICONSTOP | MB_OK
                )
                log.info(f"Blocked application alert shown for: {app_name}")
            except Exception as e:
                log.warning(f"Could not show blocked application alert: {e}")
    
    alert_thread = threading.Thread(target=_show_alert_thread, daemon=True, name=f"AppAlert-{app_name}")
    alert_thread.start()


