"""
TrackerV3 Agent Uninstaller
Removes the agent installation from the system
"""
import os
import sys
import subprocess
import shutil
import socket
import time
import json
import urllib.request
import urllib.error

# Installation path (must match installer)
if sys.platform == 'win32':
    PROGRAMDATA = os.environ.get('ProgramData', r'C:\ProgramData')
    INSTALL_DIR = os.path.join(PROGRAMDATA, 'TrackerV3Agent')
else:
    INSTALL_DIR = '/opt/TrackerV3Agent' if os.geteuid() == 0 else os.path.expanduser('~/.TrackerV3Agent')

def find_agent_processes():
    """Find running agent processes"""
    processes = []
    try:
        import psutil
        # Search for processes running agent.py from our install directory
        for proc in psutil.process_iter(['pid', 'name', 'cmdline', 'exe']):
            try:
                cmdline = proc.info.get('cmdline', [])
                if not cmdline:
                    continue
                
                cmdline_str = ' '.join(str(arg) for arg in cmdline if arg)
                
                # Check if this is our agent process
                if 'agent.py' in cmdline_str and INSTALL_DIR in cmdline_str:
                    processes.append(proc.info['pid'])
            except (psutil.NoSuchProcess, psutil.AccessDenied, psutil.ZombieProcess):
                pass
    except ImportError:
        # psutil not available, try alternative method
        print("  (Note: psutil not available, trying alternative method)")
        if sys.platform == 'win32':
            # Windows: Use tasklist and findstr
            try:
                result = subprocess.run(
                    ['tasklist', '/FI', 'IMAGENAME eq python.exe', '/FI', 'IMAGENAME eq pythonw.exe', '/FO', 'CSV'],
                    capture_output=True,
                    text=True,
                    shell=True
                )
                # Basic detection - in real scenario would parse output
                # For now, return empty and let user manually stop if needed
                pass
            except Exception:
                pass
        else:
            # Linux/Mac: Use ps and grep
            try:
                result = subprocess.run(
                    ['ps', 'aux'],
                    capture_output=True,
                    text=True
                )
                for line in result.stdout.split('\n'):
                    if 'agent.py' in line and INSTALL_DIR in line:
                        parts = line.split()
                        if parts:
                            try:
                                processes.append(int(parts[1]))  # PID is usually 2nd column
                            except (ValueError, IndexError):
                                pass
            except Exception:
                pass
    
    return processes

def stop_agent():
    """Stop the running agent process"""
    print("\nStopping agent processes...")
    processes = find_agent_processes()
    
    if not processes:
        print("  ✓ No agent processes found running")
        return True
    
    try:
        import psutil
        for pid in processes:
            try:
                proc = psutil.Process(pid)
                proc.terminate()
                print(f"  ✓ Stopping process PID {pid}...")
                # Wait for graceful shutdown
                try:
                    proc.wait(timeout=5)
                    print(f"  ✓ Process {pid} stopped")
                except psutil.TimeoutExpired:
                    # Force kill if it doesn't stop
                    proc.kill()
                    print(f"  ✓ Process {pid} force stopped")
            except (psutil.NoSuchProcess, psutil.AccessDenied) as e:
                print(f"  ! Could not stop process {pid}: {e}")
        
        # Give processes time to fully stop
        time.sleep(2)
        return True
    except ImportError:
        # Fallback method without psutil
        if sys.platform == 'win32':
            for pid in processes:
                try:
                    subprocess.run(['taskkill', '/F', '/PID', str(pid)], capture_output=True)
                    print(f"  ✓ Stopped process PID {pid}")
                except Exception as e:
                    print(f"  ! Could not stop process {pid}: {e}")
        else:
            for pid in processes:
                try:
                    subprocess.run(['kill', '-9', str(pid)], capture_output=True)
                    print(f"  ✓ Stopped process PID {pid}")
                except Exception as e:
                    print(f"  ! Could not stop process {pid}: {e}")
        time.sleep(2)
        return True
    except Exception as e:
        print(f"  ✗ Error stopping processes: {e}")
        return False

def unregister_agent(server_base=None):
    """Optionally unregister agent from server"""
    if not server_base:
        # Try to get server base from config.json
        config_path = os.path.join(INSTALL_DIR, 'config.json')
        if os.path.exists(config_path):
            try:
                with open(config_path, 'r') as f:
                    config = json.load(f)
                    server_base = config.get('server_base')
            except Exception:
                pass
    
    if not server_base:
        print("  ! No server URL found, skipping unregistration")
        return False
    
    try:
        # Get machine information
        machine_id = os.environ.get('COMPUTERNAME') or socket.gethostname()
        
        # Try to call unregister endpoint (if it exists) or just log
        print(f"  Note: Agent record in server database will remain")
        print(f"  You can manually delete it from the Agents page in the UI")
        return True
    except Exception as e:
        print(f"  ! Error during unregistration: {e}")
        return False

def remove_installation():
    """Remove the installation directory"""
    print(f"\nRemoving installation directory...")
    
    if not os.path.exists(INSTALL_DIR):
        print(f"  ✓ Installation directory not found: {INSTALL_DIR}")
        return True
    
    try:
        # Try to remove directory
        shutil.rmtree(INSTALL_DIR)
        print(f"  ✓ Removed: {INSTALL_DIR}")
        return True
    except PermissionError:
        print(f"  ✗ Permission denied. You may need to run as administrator/root")
        print(f"  Directory: {INSTALL_DIR}")
        return False
    except Exception as e:
        print(f"  ✗ Error removing directory: {e}")
        return False

def main():
    """Main uninstallation process"""
    print("=" * 50)
    print("TrackerV3 Agent Uninstaller")
    print("=" * 50)
    print(f"\nInstallation directory: {INSTALL_DIR}")
    
    # Confirm uninstallation
    if len(sys.argv) > 1 and sys.argv[1] == '--yes':
        confirm = True
    else:
        response = input("\nAre you sure you want to uninstall the agent? (yes/no): ").strip().lower()
        confirm = response in ('yes', 'y')
    
    if not confirm:
        print("\nUninstallation cancelled.")
        sys.exit(0)
    
    success = True
    
    # Step 1: Stop agent processes
    if not stop_agent():
        print("\n⚠ Warning: Could not stop all agent processes")
        print("  You may need to manually stop them or restart your computer")
        success = False
    
    # Step 2: Get server base for unregistration (optional)
    server_base = None
    config_path = os.path.join(INSTALL_DIR, 'config.json')
    if os.path.exists(config_path):
        try:
            with open(config_path, 'r') as f:
                config = json.load(f)
                server_base = config.get('server_base')
        except Exception:
            pass
    
    # Step 3: Unregister from server (informational)
    unregister_agent(server_base)
    
    # Step 4: Remove installation
    if not remove_installation():
        print("\n✗ Failed to remove installation directory")
        print(f"\nYou can manually delete: {INSTALL_DIR}")
        success = False
    
    # Final status
    print("\n" + "=" * 50)
    if success:
        print("✓ Uninstallation completed!")
        print("\nNote: The agent record may still exist in the server database.")
        print("You can delete it manually from the Agents page in the web UI.")
    else:
        print("⚠ Uninstallation completed with warnings")
        print("Please review any error messages above.")
    
    print("\nThe uninstaller will now exit.")

if __name__ == '__main__':
    try:
        main()
    except KeyboardInterrupt:
        print("\n\nUninstallation cancelled by user")
        sys.exit(1)
    except Exception as e:
        print(f"\n✗ Unexpected error: {e}")
        sys.exit(1)

