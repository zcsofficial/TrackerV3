"""
Helper script to check if agent is running with admin privileges
"""
import sys
import os

def is_admin():
    """Check if running with administrator privileges"""
    try:
        if sys.platform == 'win32':
            import ctypes
            return ctypes.windll.shell32.IsUserAnAdmin() != 0
        else:
            return os.geteuid() == 0
    except Exception:
        return False

if __name__ == '__main__':
    if is_admin():
        print("✓ Running with administrator privileges")
        sys.exit(0)
    else:
        print("⚠️  NOT running with administrator privileges")
        print("\nFor optimal website monitoring and device blocking:")
        print("  1. Right-click agent.py or start_agent.bat")
        print("  2. Select 'Run as administrator'")
        sys.exit(1)


