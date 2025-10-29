"""
TrackerV3 Agent - Main Entry Point
Refactored to use modular structure
"""
import os
import sys
import time
import base64
import sqlite3
import threading
from datetime import datetime, timedelta
import logging
from logging.handlers import RotatingFileHandler

# Add agent directory to path
APP_DIR = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, APP_DIR)

try:
    import psutil
    from pynput import mouse, keyboard
    from PIL import ImageGrab, Image
    import requests
    from concurrent.futures import ThreadPoolExecutor, as_completed
except Exception as e:
    print("Missing dependencies: psutil, pynput, pillow, requests")
    print("Install with: pip install psutil pynput pillow requests")
    sys.exit(1)

# Import modules
try:
    from config import (
        DB_PATH, SCREEN_DIR, LOG_PATH, INGEST_URL,
        USERNAME, MACHINE_ID, HOSTNAME, SERVER_BASE
    )
    from config import update_from_server_response
    import monitoring
    import permission
except ImportError:
    # Fallback for standalone execution
    print("Error: Could not import modules. Make sure config.py, monitoring.py, and permission.py exist in the agent directory.")
    sys.exit(1)

VERBOSE = os.environ.get('TRACKER_VERBOSE', '1') not in ('0', 'false', 'False')


def setup_logging():
    logger = logging.getLogger('tracker_agent')
    logger.setLevel(logging.DEBUG)

    # Rotating file handler (~2 MB x 3 files)
    fh = RotatingFileHandler(LOG_PATH, maxBytes=2 * 1024 * 1024, backupCount=3, encoding='utf-8')
    fh.setLevel(logging.DEBUG)
    fmt = logging.Formatter('%(asctime)s | %(levelname)s | %(message)s')
    fh.setFormatter(fmt)
    logger.addHandler(fh)

    if VERBOSE:
        ch = logging.StreamHandler(sys.stdout)
        ch.setLevel(logging.INFO)
        ch.setFormatter(fmt)
        logger.addHandler(ch)

    logger.debug('Logging initialized. LOG_PATH=%s VERBOSE=%s', LOG_PATH, VERBOSE)
    return logger


log = setup_logging()


def init_db():
    con = sqlite3.connect(DB_PATH)
    cur = con.cursor()
    cur.execute(
        """
        CREATE TABLE IF NOT EXISTS activity (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            start_time TEXT NOT NULL,
            end_time TEXT NOT NULL,
            productive_seconds INTEGER NOT NULL,
            unproductive_seconds INTEGER NOT NULL,
            idle_seconds INTEGER NOT NULL,
            mouse_moves INTEGER NOT NULL,
            key_presses INTEGER NOT NULL,
            synced INTEGER NOT NULL DEFAULT 0
        )
        """
    )
    cur.execute(
        """
        CREATE TABLE IF NOT EXISTS screenshots (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            taken_at TEXT NOT NULL,
            filename TEXT NOT NULL,
            synced INTEGER NOT NULL DEFAULT 0
        )
        """
    )
    con.commit()
    con.close()
    log.info('Database initialized at %s', DB_PATH)


class ActivityTracker:
    def __init__(self):
        self.last_input_time = time.time()
        self.mouse_moves = 0
        self.key_presses = 0
        self.lock = threading.Lock()
        log.info('ActivityTracker initialized')

    def on_move(self, x, y):
        with self.lock:
            self.mouse_moves += 1
            self.last_input_time = time.time()

    def on_click(self, x, y, button, pressed):
        with self.lock:
            self.last_input_time = time.time()

    def on_scroll(self, x, y, dx, dy):
        with self.lock:
            self.last_input_time = time.time()

    def on_key(self, key):
        with self.lock:
            self.key_presses += 1
            self.last_input_time = time.time()

    def collect_minute(self):
        now = datetime.utcnow()
        start = now - timedelta(minutes=1)
        with self.lock:
            mouse_moves = self.mouse_moves
            key_presses = self.key_presses
            self.mouse_moves = 0
            self.key_presses = 0
            last_input_age = time.time() - self.last_input_time

        idle_seconds = int(min(60, max(0, last_input_age))) if last_input_age > 0 else 0
        active_seconds = 60 - idle_seconds

        # For MVP, consider all active seconds as productive
        productive_seconds = active_seconds
        unproductive_seconds = 0

        record = {
            'start_time': start.strftime('%Y-%m-%d %H:%M:%S'),
            'end_time': now.strftime('%Y-%m-%d %H:%M:%S'),
            'productive_seconds': productive_seconds,
            'unproductive_seconds': unproductive_seconds,
            'idle_seconds': idle_seconds,
            'mouse_moves': mouse_moves,
            'key_presses': key_presses,
        }
        log.info('Collected minute: prod=%s unprod=%s idle=%s mouse=%s keys=%s',
                 productive_seconds, unproductive_seconds, idle_seconds, mouse_moves, key_presses)
        return record


def save_activity_local(record):
    con = sqlite3.connect(DB_PATH)
    cur = con.cursor()
    cur.execute(
        'INSERT INTO activity (start_time, end_time, productive_seconds, unproductive_seconds, idle_seconds, mouse_moves, key_presses, synced) VALUES (?,?,?,?,?,?,?,0)',
        (
            record['start_time'], record['end_time'],
            record['productive_seconds'], record['unproductive_seconds'],
            record['idle_seconds'], record['mouse_moves'], record['key_presses']
        )
    )
    con.commit()
    con.close()
    log.debug('Saved activity locally: %s -> %s', record['start_time'], record['end_time'])


def capture_screenshot():
    now = datetime.utcnow()
    img = ImageGrab.grab()
    # Compress to JPEG aiming for KBs
    fname = now.strftime('sc_%Y%m%d_%H%M%S.jpg')
    path = os.path.join(SCREEN_DIR, fname)
    img = img.convert('RGB')
    img.save(path, format='JPEG', optimize=True, quality=40)
    con = sqlite3.connect(DB_PATH)
    cur = con.cursor()
    cur.execute('INSERT INTO screenshots (taken_at, filename, synced) VALUES (?,?,0)', (now.strftime('%Y-%m-%d %H:%M:%S'), fname))
    con.commit()
    con.close()
    try:
        size_kb = int(os.path.getsize(path) / 1024)
    except Exception:
        size_kb = -1
    log.info('Captured screenshot %s (%s KB)', fname, size_kb)


def load_unsynced():
    con = sqlite3.connect(DB_PATH)
    cur = con.cursor()
    cur.execute('SELECT id, start_time, end_time, productive_seconds, unproductive_seconds, idle_seconds, mouse_moves, key_presses FROM activity WHERE synced = 0 ORDER BY id ASC LIMIT 500')
    acts = cur.fetchall()
    cur.execute('SELECT id, taken_at, filename FROM screenshots WHERE synced = 0 ORDER BY id ASC LIMIT 50')
    shots = cur.fetchall()
    con.close()
    log.debug('Loaded unsynced: %d activity, %d screenshots', len(acts), len(shots))
    return acts, shots


def mark_synced_and_cleanup(activity_ids, screenshot_items, delete_screenshots=True):
    con = sqlite3.connect(DB_PATH)
    cur = con.cursor()
    # Delete activity rows
    if activity_ids:
        q = 'DELETE FROM activity WHERE id IN ({})'.format(','.join('?' * len(activity_ids)))
        cur.execute(q, activity_ids)
    # Delete screenshot rows and files
    if screenshot_items:
        shot_ids = [str(i[0]) for i in screenshot_items]
        q = 'DELETE FROM screenshots WHERE id IN ({})'.format(','.join('?' * len(shot_ids)))
        cur.execute(q, shot_ids)
    con.commit()
    con.close()
    # Remove files on disk if configured
    removed = 0
    if delete_screenshots:
        for rid, filename in screenshot_items:
            path = os.path.join(SCREEN_DIR, filename)
            try:
                if os.path.exists(path):
                    os.remove(path)
                    removed += 1
            except Exception as e:
                log.debug('Failed to remove %s: %s', path, e)
    log.info('Cleanup done: deleted %d activity rows, %d screenshots (%d files removed)', len(activity_ids), len(screenshot_items), removed)


def sync_chunk(payload_chunk, delete_screenshots=True):
    """Sync a single chunk of data. Returns (success: bool, activity_ids: list, screenshot_items: list, server_response: dict)"""
    try:
        log.debug('Syncing chunk: %d activity, %d screenshots', len(payload_chunk.get('activity', [])), len(payload_chunk.get('screenshots', [])))
        resp = requests.post(INGEST_URL, json=payload_chunk, timeout=30)
        status = resp.status_code
        if status == 200:
            try:
                jr = resp.json()
            except Exception:
                jr = {}
            if isinstance(jr, dict) and jr.get('status') == 'ok':
                act_ids = payload_chunk.get('_activity_ids', [])
                shot_items = payload_chunk.get('_screenshot_items', [])
                jr['_delete_screenshots'] = delete_screenshots
                return (True, act_ids, shot_items, jr)
            else:
                log.warning('Sync chunk failed: unexpected JSON response')
                return (False, [], [], {})
        else:
            log.warning('Sync chunk failed: HTTP %s', status)
            return (False, [], [], {})
    except Exception as e:
        log.warning('Sync chunk error: %s', e)
        return (False, [], [], {})


def sync_now():
    acts, shots = load_unsynced()
    if not acts and not shots:
        log.debug('Nothing to sync')
        return

    from config import PARALLEL_WORKERS
    parallel_workers = int(os.environ.get('TRACKER_PARALLEL_WORKERS', str(PARALLEL_WORKERS)))
    if parallel_workers < 1:
        parallel_workers = 1
    if parallel_workers > 10:
        parallel_workers = 10

    # Prepare activity and screenshots
    all_activity = []
    all_act_ids = []
    for row in acts:
        (rid, start_time, end_time, prod, unprod, idle, mouse_moves, key_presses) = row
        all_activity.append({
            'start_time': start_time,
            'end_time': end_time,
            'productive_seconds': int(prod),
            'unproductive_seconds': int(unprod),
            'idle_seconds': int(idle),
            'mouse_moves': int(mouse_moves),
            'key_presses': int(key_presses),
        })
        all_act_ids.append(str(rid))

    all_screenshots = []
    all_shot_items = []
    for row in shots:
        rid, taken_at, filename = row
        path = os.path.join(SCREEN_DIR, filename)
        if os.path.exists(path):
            with open(path, 'rb') as f:
                data_b64 = base64.b64encode(f.read()).decode('ascii')
            all_screenshots.append({
                'taken_at': taken_at,
                'filename': filename,
                'data_base64': data_b64,
            })
        all_shot_items.append((rid, filename))

    # Single sync or parallel sync
    total_items = len(all_activity) + len(all_screenshots)
    delete_screenshots = os.environ.get('TRACKER_DELETE_SCREENSHOTS', '1') not in ('0', 'false', 'False')
    
    if parallel_workers == 1 or total_items <= 50:
        payload = {
            'username': USERNAME,
            'machine_id': MACHINE_ID,
            'hostname': HOSTNAME,
            'activity': all_activity,
            'screenshots': all_screenshots,
            '_activity_ids': all_act_ids,
            '_screenshot_items': all_shot_items,
        }
        success, act_ids, shot_items, jr = sync_chunk(payload, delete_screenshots)
        if success:
            server_delete = jr.get('delete_screenshots_after_sync')
            if server_delete is not None:
                delete_screenshots = bool(server_delete) if isinstance(server_delete, bool) else str(server_delete) not in ('0', 'false', 'False')
                os.environ['TRACKER_DELETE_SCREENSHOTS'] = '1' if delete_screenshots else '0'
            mark_synced_and_cleanup(act_ids, shot_items, delete_screenshots)
            log.info('Sync successful: %d activity, %d screenshots', len(act_ids), len(shot_items))
            update_from_server_response(jr)
    else:
        # Parallel sync
        chunks = [([], [], [], []) for _ in range(parallel_workers)]
        for i, act in enumerate(all_activity):
            chunk_idx = i % parallel_workers
            chunks[chunk_idx][0].append(act)
            chunks[chunk_idx][1].append(all_act_ids[i])
        for i, shot in enumerate(all_screenshots):
            chunk_idx = i % parallel_workers
            chunks[chunk_idx][2].append(shot)
            chunks[chunk_idx][3].append(all_shot_items[i])
        
        payloads = []
        for chunk_acts, chunk_act_ids, chunk_shots, chunk_shot_items in chunks:
            if chunk_acts or chunk_shots:
                payloads.append({
                    'username': USERNAME,
                    'machine_id': MACHINE_ID,
                    'hostname': HOSTNAME,
                    'activity': chunk_acts,
                    'screenshots': chunk_shots,
                    '_activity_ids': chunk_act_ids,
                    '_screenshot_items': chunk_shot_items,
                })
        
        if payloads:
            log.info('Syncing in parallel (%d workers): %d total activity, %d total screenshots across %d chunks',
                     parallel_workers, len(all_activity), len(all_screenshots), len(payloads))
            
            all_synced_act_ids = []
            all_synced_shot_items = []
            server_settings = {}
            
            with ThreadPoolExecutor(max_workers=parallel_workers) as executor:
                future_to_payload = {executor.submit(sync_chunk, payload, delete_screenshots): payload for payload in payloads}
                for future in as_completed(future_to_payload):
                    success, act_ids, shot_items, jr = future.result()
                    if success:
                        all_synced_act_ids.extend(act_ids)
                        all_synced_shot_items.extend(shot_items)
                        if jr:
                            server_settings.update(jr)
            
            server_delete = server_settings.get('delete_screenshots_after_sync')
            if server_delete is not None:
                delete_screenshots = bool(server_delete) if isinstance(server_delete, bool) else str(server_delete) not in ('0', 'false', 'False')
                os.environ['TRACKER_DELETE_SCREENSHOTS'] = '1' if delete_screenshots else '0'
            
            if all_synced_act_ids or all_synced_shot_items:
                mark_synced_and_cleanup(all_synced_act_ids, all_synced_shot_items, delete_screenshots)
                log.info('Parallel sync successful: %d activity, %d screenshots', len(all_synced_act_ids), len(all_synced_shot_items))
            
            update_from_server_response(server_settings)


def main():
    init_db()

    tracker = ActivityTracker()
    m_listener = mouse.Listener(on_move=tracker.on_move, on_click=tracker.on_click, on_scroll=tracker.on_scroll)
    k_listener = keyboard.Listener(on_press=lambda key: tracker.on_key(key))
    m_listener.start()
    k_listener.start()
    log.info('Listeners started. USERNAME=%s MACHINE_ID=%s HOSTNAME=%s SERVER=%s', USERNAME, MACHINE_ID, HOSTNAME, SERVER_BASE)

    last_screenshot = time.time()
    last_device_scan = time.time()
    interval_env = os.environ.get('TRACKER_SYNC_INTERVAL')
    loop_interval = int(interval_env) if (interval_env and interval_env.isdigit()) else 60
    
    while True:
        try:
            if loop_interval < 15:
                loop_interval = 15
            time.sleep(loop_interval)
            
            # Collect activity
            record = tracker.collect_minute()
            save_activity_local(record)

            # Take screenshot every 5 minutes
            if time.time() - last_screenshot >= 300:
                capture_screenshot()
                last_screenshot = time.time()

            # Device monitoring (scans periodically)
            if time.time() - last_device_scan >= 30:  # Scan every 30 seconds
                try:
                    monitoring.scan_devices()
                    last_device_scan = time.time()
                except Exception as e:
                    log.debug(f"Device scan error: {e}")

            # Sync
            sync_now()
            
        except KeyboardInterrupt:
            log.info('Interrupted by user. Exiting...')
            break
        except Exception as e:
            log.exception('Loop error: %s', e)
            time.sleep(5)


if __name__ == '__main__':
    main()

