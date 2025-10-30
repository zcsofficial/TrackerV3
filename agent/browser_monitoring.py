"""
Browser Monitoring Module for TrackerV3 Agent
Monitors browser tabs, URLs, and private/incognito mode
Rebuilt for reliable detection of all browsers
"""
import os
import sys
import time
import logging
import requests
import re
import sqlite3
from datetime import datetime, timedelta
from urllib.parse import urlparse
import threading

# Add parent directory to path for imports
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

try:
    from .config import (
        MACHINE_ID, USERNAME, HOSTNAME, is_website_monitoring_enabled, get_website_monitoring_interval,
        WEBSITE_API_URL
    )
except ImportError:
    from config import (
        MACHINE_ID, USERNAME, HOSTNAME, is_website_monitoring_enabled, get_website_monitoring_interval,
        WEBSITE_API_URL
    )

log = logging.getLogger('tracker_agent.browser_monitoring')

# Track active browser tabs
_active_tabs = {}
_last_scan_time = 0
_scan_throttle = 1  # Minimum seconds between scans (1s for real-time)
_browser_cache = {}  # Cache for browser process checks
_last_report_time_by_key = {}
_report_cooldown_seconds = int(os.environ.get('TRACKER_BROWSER_REPORT_COOLDOWN_SECONDS', '5'))

def _get_domain_from_url(url):
    """Extract domain from URL"""
    try:
        parsed = urlparse(url)
        domain = parsed.netloc.lower()
        # Remove www. prefix and port
        if domain.startswith('www.'):
            domain = domain[4:]
        if ':' in domain:
            domain = domain.split(':')[0]
        return domain
    except Exception:
        return None

def _detect_browser_type(process_name):
    """Detect browser type from process name"""
    process_lower = process_name.lower()
    if 'chrome.exe' in process_lower and 'msedge' not in process_lower:
        return 'Chrome'
    elif 'msedge.exe' in process_lower or 'edge.exe' in process_lower:
        return 'Edge'
    elif 'firefox.exe' in process_lower:
        return 'Firefox'
    elif 'opera.exe' in process_lower:
        return 'Opera'
    elif 'brave.exe' in process_lower:
        return 'Brave'
    elif 'vivaldi.exe' in process_lower:
        return 'Vivaldi'
    return 'Unknown'

def _is_private_mode(process_name, window_title):
    """Detect if browser is in private/incognito mode"""
    title_lower = (window_title or '').lower()
    
    browser = _detect_browser_type(process_name).lower()
    if browser == 'chrome':
        return _check_chrome_incognito(process_name)
    elif browser == 'edge':
        return 'inprivate' in title_lower or _check_edge_inprivate(process_name)
    elif browser == 'firefox':
        return 'private' in title_lower or _check_firefox_private(process_name)
    
    return False

def _check_chrome_incognito(process_name):
    """Check if Chrome is in incognito mode"""
    try:
        import psutil
        for proc in psutil.process_iter(['pid', 'name', 'cmdline']):
            try:
                if proc.info['name'] and 'chrome' in proc.info['name'].lower():
                    cmdline = proc.info.get('cmdline', [])
                    if cmdline and any('--incognito' in str(arg).lower() for arg in cmdline):
                        return True
            except (psutil.NoSuchProcess, psutil.AccessDenied):
                continue
    except Exception:
        pass
    return False

def _check_edge_inprivate(process_name):
    """Check if Edge is in InPrivate mode"""
    try:
        import psutil
        for proc in psutil.process_iter(['pid', 'name', 'cmdline']):
            try:
                if proc.info['name'] and 'msedge' in proc.info['name'].lower():
                    cmdline = proc.info.get('cmdline', [])
                    if cmdline and any('inprivate' in str(arg).lower() for arg in cmdline):
                        return True
            except (psutil.NoSuchProcess, psutil.AccessDenied):
                continue
    except Exception:
        pass
    return False

def _check_firefox_private(process_name):
    """Check if Firefox is in private mode"""
    try:
        import psutil
        for proc in psutil.process_iter(['pid', 'name', 'cmdline']):
            try:
                if proc.info['name'] and 'firefox' in proc.info['name'].lower():
                    cmdline = proc.info.get('cmdline', [])
                    if cmdline and any('private' in str(arg).lower() for arg in cmdline):
                        return True
            except (psutil.NoSuchProcess, psutil.AccessDenied):
                continue
    except Exception:
        pass
    return False

def _is_admin():
    """Check if running with admin privileges"""
    try:
        if sys.platform == 'win32':
            import ctypes
            return ctypes.windll.shell32.IsUserAnAdmin() != 0
        else:
            return os.geteuid() == 0
    except Exception:
        return False

def _parse_domain_from_title(window_title, browser_type):
    """Parse domain from browser window title - RELIABLE METHOD"""
    """
    Chrome/Edge format examples:
    - "Page Title - example.com - Google Chrome"
    - "Page Title - example.com"
    - "example.com - Google Chrome"
    - "YouTube - Google Chrome" (no domain, just page title)
    
    Firefox format:
    - "Page Title - Mozilla Firefox"
    """
    if not window_title or len(window_title.strip()) < 3:
        return None, None, None
    
    title = window_title.strip()
    title_lower = title.lower()
    
    # Common browser name suffixes to remove
    browser_suffixes = [
        ' - google chrome',
        ' - microsoft edge',
        ' - edge',
        ' - mozilla firefox',
        ' - firefox',
        ' - opera',
        ' - brave',
        ' - vivaldi'
    ]
    
    # Remove browser suffix
    cleaned_title = title
    for suffix in browser_suffixes:
        if title_lower.endswith(suffix.lower()):
            cleaned_title = cleaned_title[:-len(suffix)].strip()
            break
    
    # Now try to extract domain from cleaned title
    # Format is usually: "Page Title - domain.com" or just "domain.com"
    
    # Support both hyphen and en dash separators
    sep = ' - ' if ' - ' in cleaned_title else (' – ' if ' – ' in cleaned_title else None)
    # Method 1: Check if title contains separator
    if sep:
        parts = cleaned_title.rsplit(sep, 1)
        if len(parts) == 2:
            page_title = parts[0].strip()
            domain_part = parts[1].strip().lower()
            
            # Validate domain_part looks like a domain
            if _is_valid_domain(domain_part):
                domain = domain_part
                url = f"https://{domain}"
                return url, domain, page_title
    
    # Method 2: Check if entire title is a domain
    if _is_valid_domain(cleaned_title.lower()):
        domain = cleaned_title.lower()
        url = f"https://{domain}"
        return url, domain, cleaned_title
    
    # Method 3: Extract domain using regex
    domain_pattern = r'\b([a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}\b'
    matches = re.findall(domain_pattern, cleaned_title, re.IGNORECASE)
    if matches:
        for match in matches:
            potential_domain = match[0].lower()
            if _is_valid_domain(potential_domain):
                domain = potential_domain
                url = f"https://{domain}"
                # Use title without domain as page title
                page_title = re.sub(domain_pattern, '', cleaned_title, flags=re.IGNORECASE).strip()
                page_title = re.sub(r'\s+[-–]\s*$', '', page_title).strip()  # Remove trailing dash
                if not page_title:
                    page_title = domain
                return url, domain, page_title
    
    return None, None, None

def _is_valid_domain(domain_str):
    """Validate if string looks like a real domain"""
    if not domain_str or len(domain_str) < 4:
        return False
    
    # Must contain a dot
    if '.' not in domain_str:
        return False
    
    # Cannot contain spaces
    if ' ' in domain_str:
        return False
    
    # Cannot be just browser names
    browser_names = ['chrome', 'edge', 'firefox', 'opera', 'brave', 'vivaldi', 'browser', 'new tab', 'new window']
    if domain_str.lower() in browser_names:
        return False
    
    # Must have valid TLD (at least 2 characters after last dot)
    parts = domain_str.split('.')
    if len(parts) < 2:
        return False
    
    tld = parts[-1]
    if len(tld) < 2:
        return False
    
    # Check common TLDs
    common_tlds = ['com', 'net', 'org', 'io', 'co', 'edu', 'gov', 'us', 'uk', 'de', 'fr', 'jp', 'cn', 'in', 'au', 'ca', 'br', 'ru', 'es', 'it', 'dev', 'app', 'ai', 'me', 'info', 'biz']
    if tld.lower() not in common_tlds and len(tld) < 2:
        return False
    
    # Remove www. if present for validation
    domain_check = domain_str[4:] if domain_str.startswith('www.') else domain_str
    
    # Check it's not an IP address or localhost
    if domain_check.startswith(('127.', '192.168.', '10.', '172.')) or domain_check == 'localhost':
        return False
    
    # Check it's not a CDN/cloud domain pattern (we want actual sites)
    cloud_patterns = ['cloudfront.net', 'amazonaws.com', 'cdn', 'edge', 'cloudflare', 'fastly']
    if any(pattern in domain_check.lower() for pattern in cloud_patterns):
        return False
    
    return True

def _get_browser_tabs_windows():
    """Get active browser tabs using Windows API - RELIABLE METHOD"""
    tabs = []
    if sys.platform != 'win32':
        return tabs
    
    is_admin_mode = _is_admin()
    
    try:
        import psutil
        import win32gui
        import win32process
        import win32con
        
        browser_processes = {}
        detected_browsers = set()
        
        def enum_windows_callback(hwnd, result):
            try:
                # Only check visible, non-minimized windows
                if not win32gui.IsWindowVisible(hwnd):
                    return True
                
                if win32gui.IsIconic(hwnd):
                    return True
                
                window_title = win32gui.GetWindowText(hwnd)
                if not window_title or len(window_title.strip()) < 3:
                    return True
                
                # Skip empty or generic titles
                if window_title.lower() in ['', 'chrome', 'edge', 'firefox', 'new tab', 'new window', 'browser']:
                    return True
                
                # Get process ID
                try:
                    _, pid = win32process.GetWindowThreadProcessId(hwnd)
                except Exception:
                    return True
                
                # Identify browser by process
                if pid not in browser_processes:
                    try:
                        proc = psutil.Process(pid)
                        proc_name = proc.name().lower()
                        proc_exe = proc.exe().lower() if hasattr(proc, 'exe') else ''
                        
                        browser_type = None
                        if 'chrome.exe' in proc_exe or proc_name == 'chrome.exe':
                            browser_type = 'Chrome'
                        elif 'msedge.exe' in proc_exe or proc_name == 'msedge.exe':
                            browser_type = 'Edge'
                        elif 'firefox.exe' in proc_exe or proc_name == 'firefox.exe':
                            browser_type = 'Firefox'
                        elif 'opera.exe' in proc_exe or proc_name == 'opera.exe':
                            browser_type = 'Opera'
                        elif 'brave.exe' in proc_exe or proc_name == 'brave.exe':
                            browser_type = 'Brave'
                        elif 'vivaldi.exe' in proc_exe or proc_name == 'vivaldi.exe':
                            browser_type = 'Vivaldi'
                        
                        browser_processes[pid] = (proc, browser_type)
                        if browser_type:
                            detected_browsers.add(browser_type)
                    except (psutil.NoSuchProcess, psutil.AccessDenied):
                        return True
                    except Exception:
                        return True
                
                proc_info = browser_processes.get(pid)
                if not proc_info:
                    return True
                
                proc, browser_type = proc_info
                
                if not browser_type:
                    return True  # Not a browser window
                
                # Parse domain from window title
                url, domain, page_title = _parse_domain_from_title(window_title, browser_type)
                
                # If no domain found, skip (don't use network connections as primary)
                if not url or not domain:
                    return True
                
                # Validate domain is not a CDN/cloud domain
                if not _is_valid_domain(domain):
                    return True
                
                # Check for duplicates
                tab_key = f"{browser_type}:{domain}:{hwnd}"
                existing_keys = {f"{t['browser']}:{t['domain']}:{t.get('window_id', 0)}" for t in tabs}
                if tab_key in existing_keys:
                    return True
                
                is_private = _is_private_mode(proc.name(), window_title)
                
                tabs.append({
                    'url': url,
                    'domain': domain,
                    'title': page_title or window_title,
                    'browser': browser_type,
                    'is_private': is_private,
                    'is_incognito': is_private if browser_type in ['Chrome', 'Edge'] else False,
                    'window_id': hwnd,
                    'process_id': pid
                })
                
            except Exception as e:
                log.debug(f"Error processing window {hwnd}: {e}")
            return True
        
        win32gui.EnumWindows(enum_windows_callback, None)
        
        if tabs:
            log.debug(f"Detected {len(tabs)} browser tabs from {len(detected_browsers)} browser(s): {', '.join(detected_browsers)}")
        elif detected_browsers:
            log.debug(f"Found browser processes: {', '.join(detected_browsers)} but no valid tabs detected")
        else:
            log.debug("No browser processes detected")
        
    except ImportError as e:
        log.warning(f"Windows API not available: {e}")
    except Exception as e:
        log.warning(f"Error getting browser tabs: {e}")
        import traceback
        log.debug(f"Traceback: {traceback.format_exc()}")
    
    return tabs

def _read_browser_tabs_windows():
    """Get active tabs from all browsers - PRIMARY METHOD"""
    all_tabs = []
    
    if sys.platform == 'win32':
        # Method 1: Windows API - window title parsing (BEST METHOD)
        tabs = _get_browser_tabs_windows()
        all_tabs.extend(tabs)
        
        # Method 2: Read from browser history (supplement for active tabs)
        if not all_tabs:
            try:
                history_tabs = _get_tabs_from_history()
                all_tabs.extend(history_tabs)
            except Exception as e:
                log.debug(f"History method failed: {e}")
    
    return all_tabs

def _get_tabs_from_history():
    """Read recent tabs from browser history files"""
    tabs = []
    
    if sys.platform != 'win32':
        return tabs
    
    try:
        # Chrome/Edge history across multiple profiles
        local_app = os.environ.get('LOCALAPPDATA', '')
        chrome_base = os.path.join(local_app, r'Google\Chrome\User Data')
        edge_base = os.path.join(local_app, r'Microsoft\Edge\User Data')
        profile_names = ['Default'] + [f'Profile {i}' for i in range(1, 6)]
        history_sources = []
        for prof in profile_names:
            if chrome_base:
                history_sources.append((os.path.join(chrome_base, prof, 'History'), 'Chrome'))
            if edge_base:
                history_sources.append((os.path.join(edge_base, prof, 'History'), 'Edge'))

        for history_path, browser_type in history_sources:
            if os.path.exists(history_path):
                try:
                    import shutil
                    import tempfile
                    temp_file = tempfile.NamedTemporaryFile(delete=False, suffix='.db')
                    temp_file.close()
                    try:
                        shutil.copy2(history_path, temp_file.name)
                        conn = sqlite3.connect(temp_file.name)
                        cursor = conn.cursor()
                        cursor.execute("""
                            SELECT url, title, last_visit_time
                            FROM urls
                            WHERE last_visit_time > ?
                            ORDER BY last_visit_time DESC
                            LIMIT 10
                        """, [int((datetime.now() - timedelta(minutes=5) - datetime(1601, 1, 1)).total_seconds() * 1000000)])
                        for row in cursor.fetchall():
                            url, title, last_visit_time = row
                            if url:
                                domain = _get_domain_from_url(url)
                                if domain and _is_valid_domain(domain):
                                    visit_time = datetime(1601, 1, 1) + timedelta(microseconds=last_visit_time)
                                    if (datetime.now() - visit_time).total_seconds() < 300:  # Last 5 minutes
                                        tabs.append({
                                            'url': url,
                                            'domain': domain,
                                            'title': title or domain,
                                            'browser': browser_type,
                                            'is_private': False,
                                            'is_incognito': False,
                                            'window_id': None,
                                            'process_id': None
                                        })
                        conn.close()
                    finally:
                        try:
                            os.unlink(temp_file.name)
                        except Exception:
                            pass
                except Exception:
                    continue

        # Firefox history (places.sqlite)
        try:
            roaming = os.environ.get('APPDATA', '')
            ff_base = os.path.join(roaming, r'Mozilla\Firefox\Profiles')
            if os.path.isdir(ff_base):
                for prof in os.listdir(ff_base):
                    db_path = os.path.join(ff_base, prof, 'places.sqlite')
                    if os.path.exists(db_path):
                        import shutil
                        import tempfile
                        temp_file = tempfile.NamedTemporaryFile(delete=False, suffix='.sqlite')
                        temp_file.close()
                        try:
                            shutil.copy2(db_path, temp_file.name)
                            conn = sqlite3.connect(temp_file.name)
                            cursor = conn.cursor()
                            cursor.execute("""
                                SELECT url, title, last_visit_date
                                FROM moz_places
                                WHERE last_visit_date IS NOT NULL AND last_visit_date > ?
                                ORDER BY last_visit_date DESC
                                LIMIT 10
                            """, [int((datetime.now() - timedelta(minutes=5)).timestamp() * 1_000_000)])
                            for row in cursor.fetchall():
                                url, title, last_visit_date = row
                                if url:
                                    domain = _get_domain_from_url(url)
                                    if domain and _is_valid_domain(domain):
                                        tabs.append({
                                            'url': url,
                                            'domain': domain,
                                            'title': title or domain,
                                            'browser': 'Firefox',
                                            'is_private': False,
                                            'is_incognito': False,
                                            'window_id': None,
                                            'process_id': None
                                        })
                            conn.close()
                        finally:
                            try:
                                os.unlink(temp_file.name)
                            except Exception:
                                pass
        except Exception:
            pass

    except Exception as e:
        log.debug(f"History reading failed: {e}")
    
    return tabs

def scan_browser_tabs():
    """Scan for active browser tabs and report to server - REAL-TIME"""
    if not is_website_monitoring_enabled():
        return
    
    global _last_scan_time, _active_tabs
    
    current_time = time.time()
    monitoring_interval = get_website_monitoring_interval()
    
    # Throttle for real-time (1 second or less, always scan)
    if monitoring_interval > 1 and (current_time - _last_scan_time < _scan_throttle):
        return
    
    _last_scan_time = current_time
    
    try:
        log.info("🔍 Scanning for active browser tabs...")
        current_tabs = _read_browser_tabs_windows()
        
        if current_tabs:
            log.info(f"🌐 Found {len(current_tabs)} active browser tab(s)")
            for tab in current_tabs:
                log.info(f"   • {tab.get('domain', 'Unknown')} ({tab.get('browser', 'Unknown')}) - {tab.get('title', '')[:50]}")
        else:
            log.info("⚠️ No active browser tabs detected")
            log.debug("   Tips: 1) Open browser with tabs, 2) Browser window must be visible, 3) Run as admin for better detection")
        
        # Track tab changes
        current_tab_keys = set()
        for tab in current_tabs:
            tab_key = f"{tab['browser']}:{tab['domain']}:{tab.get('window_id', 0)}"
            current_tab_keys.add(tab_key)
            # Cooldown to avoid duplicate reporting spam
            last_sent = _last_report_time_by_key.get(tab_key, 0)
            if _report_cooldown_seconds > 0 and (time.time() - last_sent) < _report_cooldown_seconds:
                continue

            if tab_key not in _active_tabs:
                # New tab detected
                log.info(f"📱 NEW TAB: {tab['domain']} ({tab['browser']}) - {tab.get('title', '')[:50]}")
                _active_tabs[tab_key] = {
                    'tab': tab,
                    'start_time': datetime.utcnow(),
                    'reported': False
                }
                
                # Report immediately
                try:
                    report_website_visit(tab['url'], tab['domain'], tab.get('title', ''), 
                                       tab['browser'], tab['is_private'] or tab['is_incognito'])
                    _active_tabs[tab_key]['reported'] = True
                    _last_report_time_by_key[tab_key] = time.time()
                except Exception as e:
                    log.warning(f"Failed to report website visit: {e}")
            else:
                # Update existing tab
                _active_tabs[tab_key]['tab'] = tab
        
        # Check for closed tabs
        for tab_key in list(_active_tabs.keys()):
            if tab_key not in current_tab_keys:
                tab_info = _active_tabs[tab_key]
                tab = tab_info['tab']
                start_time = tab_info['start_time']
                end_time = datetime.utcnow()
                duration = int((end_time - start_time).total_seconds())
                
                log.info(f"🔒 TAB CLOSED: {tab['domain']} (Duration: {duration}s)")
                
                try:
                    update_visit_duration(tab['url'], tab['domain'], duration, end_time)
                except Exception as e:
                    log.warning(f"Failed to update visit duration: {e}")
                
                del _active_tabs[tab_key]
    
    except Exception as e:
        log.error(f"Error scanning browser tabs: {e}")
        import traceback
        log.error(f"Traceback: {traceback.format_exc()}")

def report_website_visit(url, domain, title, browser, is_private=False):
    """Report website visit to server"""
    try:
        payload = {
            'machine_id': MACHINE_ID,
            'user_id': USERNAME,  # API expects user_id which is username
            'domain': domain,
            'url': url,  # API expects 'url' for full_url
            'full_url': url,  # Also provide full_url for compatibility
            'title': title,
            'browser': browser,
            'is_private': 1 if is_private else 0,
            'is_incognito': 1 if is_private else 0,
            'visit_start': datetime.utcnow().strftime('%Y-%m-%d %H:%M:%S')
        }
        
        response = requests.post(
            WEBSITE_API_URL,
            json=payload,
            timeout=10
        )
        
        if response.status_code == 200:
            result = response.json()
            if result.get('is_blocked'):
                log.warning(f"⚠️ BLOCKED WEBSITE ACCESSED: {domain}")
                _show_blocked_website_alert(domain)
            log.debug(f"Website visit reported: {domain}")
            return True
        else:
            log.warning(f"Failed to report website visit: Status {response.status_code}")
            return False
    except Exception as e:
        log.debug(f"Error reporting website visit: {e}")
        return False

def update_visit_duration(url, domain, duration_seconds, end_time):
    """Update visit duration on server"""
    try:
        payload = {
            'action': 'update_duration',
            'machine_id': MACHINE_ID,
            'user_id': USERNAME,
            'domain': domain,
            'url': url,
            'duration_seconds': duration_seconds,
            'visit_end': end_time.strftime('%Y-%m-%d %H:%M:%S')
        }
        
        response = requests.post(
            WEBSITE_API_URL,
            json=payload,
            timeout=10
        )
        
        if response.status_code == 200:
            log.debug(f"Visit duration updated: {domain} ({duration_seconds}s)")
            return True
        else:
            log.debug(f"Failed to update visit duration: Status {response.status_code}")
            return False
    except Exception as e:
        log.debug(f"Error updating visit duration: {e}")
        return False

def _show_blocked_website_alert(domain):
    """Show popup alert when blocked website is accessed - NON-BLOCKING"""
    def _show_alert_thread():
        if sys.platform == 'win32':
            try:
                import ctypes
                result = ctypes.windll.user32.MessageBoxW(
                    0,
                    f"Website '{domain}' is blocked by security policy.\n\nAccess denied.\n\nAgent continues tracking in background.",
                    "Website Blocked - TrackerV3 Agent",
                    0x10 | 0x0  # MB_ICONSTOP | MB_OK
                )
                log.info(f"Blocked website alert shown for: {domain}")
            except Exception as e:
                log.warning(f"Could not show blocked website alert: {e}")
    
    alert_thread = threading.Thread(target=_show_alert_thread, daemon=True, name=f"WebsiteAlert-{domain}")
    alert_thread.start()
