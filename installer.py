"""
TrackerV3 Agent Installer
Downloads the agent from the server and installs it to ProgramData folder
"""
import os
import sys
import shutil
import subprocess
import urllib.request
import urllib.error
import json
import socket
import time

# Default installation path - ProgramData on Windows
if sys.platform == 'win32':
    PROGRAMDATA = os.environ.get('ProgramData', r'C:\ProgramData')
    INSTALL_DIR = os.path.join(PROGRAMDATA, 'TrackerV3Agent')
    DATA_DIR = os.path.join(INSTALL_DIR, 'data')
    SCREEN_DIR = os.path.join(DATA_DIR, 'screenshots')
else:
    # Linux/Mac - use /opt or home directory
    INSTALL_DIR = '/opt/TrackerV3Agent' if os.geteuid() == 0 else os.path.expanduser('~/.TrackerV3Agent')
    DATA_DIR = os.path.join(INSTALL_DIR, 'data')
    SCREEN_DIR = os.path.join(DATA_DIR, 'screenshots')

# Default server (can be overridden)
DEFAULT_SERVER = 'http://localhost:8080'

def get_server_base():
    """Get server base URL from user or environment"""
    server_env = os.environ.get('TRACKER_SERVER_BASE')
    if server_env:
        return server_env
    
    print("TrackerV3 Agent Installer")
    print("=" * 50)
    server = input(f"Enter server URL (default: {DEFAULT_SERVER}): ").strip()
    if not server:
        server = DEFAULT_SERVER
    
    # Ensure URL has protocol
    if not server.startswith(('http://', 'https://')):
        server = 'http://' + server
    
    return server.rstrip('/')

def download_agent_files(server_base):
    """Download all agent files from server"""
    files_to_download = ['agent.py', 'config.py', 'monitoring.py', 'permission.py']
    downloaded_files = {}
    
    print(f"\nDownloading agent files from server...")
    
    for filename in files_to_download:
        download_url = f"{server_base}/api/download_agent.php?file={filename}"
        print(f"  Downloading {filename}...", end=' ')
        
        try:
            with urllib.request.urlopen(download_url, timeout=30) as response:
                if response.status == 200:
                    content = response.read().decode('utf-8')
                    downloaded_files[filename] = content
                    print("✓")
                else:
                    print(f"✗ (Status: {response.status})")
                    return None
        except urllib.error.URLError as e:
            print(f"✗ ({e})")
            return None
    
    print("✓ All agent files downloaded successfully")
    return downloaded_files

def install_agent_files(agent_files, server_base):
    """Install all agent files to ProgramData folder"""
    print(f"\nInstalling to: {INSTALL_DIR}")
    
    try:
        # Create directories
        os.makedirs(INSTALL_DIR, exist_ok=True)
        os.makedirs(DATA_DIR, exist_ok=True)
        os.makedirs(SCREEN_DIR, exist_ok=True)
        
        # Write all agent files
        for filename, content in agent_files.items():
            file_path = os.path.join(INSTALL_DIR, filename)
            with open(file_path, 'w', encoding='utf-8') as f:
                f.write(content)
            print(f"✓ {filename} installed to: {file_path}")
        
        # Create requirements.txt
        requirements_path = os.path.join(INSTALL_DIR, 'requirements.txt')
        with open(requirements_path, 'w') as f:
            f.write("""psutil>=5.9.0
pynput>=1.7.6
pillow>=10.0.0
requests>=2.31.0
wmi>=1.5.1
pywin32>=306
""")
        print(f"✓ Requirements file created: {requirements_path}")
        
        # Create config.json with server base
        config_path = os.path.join(INSTALL_DIR, 'config.json')
        config = {
            'server_base': server_base,
            'install_path': INSTALL_DIR,
            'data_dir': DATA_DIR
        }
        with open(config_path, 'w') as f:
            json.dump(config, f, indent=2)
        print(f"✓ Configuration saved: {config_path}")
        
        return True
        
    except Exception as e:
        print(f"✗ Installation failed: {e}")
        return False

def install_dependencies():
    """Install Python dependencies"""
    print("\nInstalling dependencies...")
    requirements_path = os.path.join(INSTALL_DIR, 'requirements.txt')
    
    if not os.path.exists(requirements_path):
        print("✗ Requirements file not found")
        return False
    
    try:
        # Check if pip is available
        result = subprocess.run([sys.executable, '-m', 'pip', '--version'], 
                              capture_output=True, text=True)
        if result.returncode != 0:
            print("✗ pip not found. Please install pip first.")
            return False
        
        # Install requirements
        result = subprocess.run([sys.executable, '-m', 'pip', 'install', '-r', requirements_path],
                              capture_output=True, text=True, cwd=INSTALL_DIR)
        if result.returncode == 0:
            print("✓ Dependencies installed successfully")
            return True
        else:
            print(f"✗ Failed to install dependencies: {result.stderr}")
            return False
    except Exception as e:
        print(f"✗ Error installing dependencies: {e}")
        return False

def create_startup_script():
    """Create a script to run the agent"""
    if sys.platform == 'win32':
        # Windows batch file
        script_path = os.path.join(INSTALL_DIR, 'start_agent.bat')
        with open(script_path, 'w') as f:
            f.write(f"""@echo off
cd /d "{INSTALL_DIR}"
pythonw agent.py
""")
        print(f"✓ Startup script created: {script_path}")
        
        # Also create PowerShell version
        ps_path = os.path.join(INSTALL_DIR, 'start_agent.ps1')
        with open(ps_path, 'w') as f:
            f.write(f"""Set-Location "{INSTALL_DIR}"
Start-Process pythonw -ArgumentList "agent.py" -WindowStyle Hidden
""")
    else:
        # Linux/Mac shell script
        script_path = os.path.join(INSTALL_DIR, 'start_agent.sh')
        with open(script_path, 'w') as f:
            f.write(f"""#!/bin/bash
cd "{INSTALL_DIR}"
python3 agent.py &
""")
        os.chmod(script_path, 0o755)
        print(f"✓ Startup script created: {script_path}")

def register_agent(server_base):
    """Register/onboard the agent with the server"""
    try:
        # Get machine information
        machine_id = os.environ.get('COMPUTERNAME') or socket.gethostname()
        hostname = socket.gethostname()
        username = os.environ.get('USERNAME') or os.environ.get('USER') or 'unknown'
        
        # Try to get display name, email, UPN from system (if available)
        display_name = None
        email = None
        upn = None
        
        # On Windows, try to get user info
        if sys.platform == 'win32':
            try:
                import win32security
                import win32api
                try:
                    user_sid = win32security.LookupAccountName(None, username)[0]
                    account_name = win32security.LookupAccountSid(None, user_sid)[1]
                    display_name = account_name
                except:
                    display_name = username
            except ImportError:
                display_name = username
        
        # Prepare registration payload
        payload = {
            'machine_id': machine_id,
            'hostname': hostname,
            'username': username,
            'display_name': display_name,
            'email': email,
            'upn': upn
        }
        
        # Register with server
        register_url = f"{server_base}/api/register_agent.php"
        print(f"\nRegistering agent with server...")
        
        req = urllib.request.Request(
            register_url,
            data=json.dumps(payload).encode('utf-8'),
            headers={'Content-Type': 'application/json'}
        )
        
        with urllib.request.urlopen(req, timeout=30) as response:
            if response.status == 200:
                result = json.loads(response.read().decode('utf-8'))
                if result.get('status') == 'ok':
                    print("✓ Agent registered successfully")
                    return True
                else:
                    print(f"✗ Registration failed: {result.get('message', 'Unknown error')}")
                    return False
            else:
                print(f"✗ Server returned status {response.status}")
                return False
    except urllib.error.URLError as e:
        print(f"✗ Failed to register agent: {e}")
        print("  (Agent will still work, but may need manual registration)")
        return False
    except Exception as e:
        print(f"✗ Registration error: {e}")
        return False

def start_agent():
    """Start the agent in the background"""
    agent_path = os.path.join(INSTALL_DIR, 'agent.py')
    
    if not os.path.exists(agent_path):
        print("✗ Agent file not found")
        return False
    
    try:
        if sys.platform == 'win32':
            # Windows: Find pythonw.exe in same directory as python.exe
            python_dir = os.path.dirname(sys.executable)
            pythonw_path = os.path.join(python_dir, 'pythonw.exe')
            
            # Use pythonw if available, otherwise use python
            if os.path.exists(pythonw_path):
                exe = pythonw_path
            else:
                exe = sys.executable
            
            # Windows constants for process creation
            CREATE_NO_WINDOW = 0x08000000
            DETACHED_PROCESS = 0x00000008
            
            # Start agent as detached background process
            process = subprocess.Popen(
                [exe, agent_path],
                cwd=INSTALL_DIR,
                creationflags=CREATE_NO_WINDOW | DETACHED_PROCESS,
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
                stdin=subprocess.DEVNULL,
                close_fds=True
            )
            
            # Give it a moment to start
            time.sleep(1)
            
            # Check if process is still running
            if process.poll() is None:
                print("✓ Agent started in background (PID: {})".format(process.pid))
                return True
            else:
                # If first method failed, try with start command
                try:
                    subprocess.Popen(
                        ['cmd', '/c', 'start', '/B', '/MIN', exe, agent_path],
                        cwd=INSTALL_DIR,
                        shell=False,
                        stdout=subprocess.DEVNULL,
                        stderr=subprocess.DEVNULL,
                        stdin=subprocess.DEVNULL
                    )
                    time.sleep(1)
                    print("✓ Agent started in background")
                    return True
                except Exception as e2:
                    print(f"✗ Failed to start agent: {e2}")
                    return False
        else:
            # Linux/Mac: Run as daemon in new session
            process = subprocess.Popen(
                [sys.executable, agent_path],
                cwd=INSTALL_DIR,
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
                stdin=subprocess.DEVNULL,
                start_new_session=True,
                close_fds=True
            )
            time.sleep(1)
            if process.poll() is None:
                print("✓ Agent started in background (PID: {})".format(process.pid))
                return True
            else:
                print("✗ Failed to start agent")
                return False
    except Exception as e:
        print(f"✗ Error starting agent: {e}")
        print(f"  You can start it manually: python {agent_path}")
        return False

def main():
    """Main installation process"""
    try:
        server_base = get_server_base()
        
        # Download all agent files
        agent_files = download_agent_files(server_base)
        if not agent_files:
            print("\n✗ Installation aborted")
            sys.exit(1)
        
        # Install all agent files
        if not install_agent_files(agent_files, server_base):
            print("\n✗ Installation aborted")
            sys.exit(1)
        
        # Install dependencies
        install_dependencies()
        
        # Create startup scripts
        create_startup_script()
        
        print("\n" + "=" * 50)
        print("✓ Installation completed successfully!")
        print(f"\nAgent installed to: {INSTALL_DIR}")
        
        # Register agent with server
        print("\nRegistering agent...")
        register_agent(server_base)
        
        # Automatically start the agent
        print("\nStarting agent...")
        start_agent()
        
        print("\n" + "=" * 50)
        print("✓ Agent is now running in the background and onboarded!")
        print("\nThe installer will now exit.")
        print("\nTo stop the agent, find the Python process running agent.py")
        
    except KeyboardInterrupt:
        print("\n\nInstallation cancelled by user")
        sys.exit(1)
    except Exception as e:
        print(f"\n✗ Unexpected error: {e}")
        sys.exit(1)

if __name__ == '__main__':
    main()

