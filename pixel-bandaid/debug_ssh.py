import os
import paramiko
import logging
from dotenv import load_dotenv

def test_ssh():
    load_dotenv()
    
    # Enable verbose paramiko logging
    paramiko.util.log_to_file('paramiko_debug.log')
    
    ssh_host = os.getenv("SSH_HOST")
    ssh_user = os.getenv("SSH_USER")
    ssh_key_path = os.getenv("SSH_KEY_PATH")
    ssh_port = int(os.getenv("SSH_PORT", 22))

    print(f"Attempting SSH to {ssh_host}:{ssh_port} as {ssh_user}...")
    print(f"Using key: {ssh_key_path}")

    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    
    try:
        # Explicitly load the key to see if that's the issue
        pkey = paramiko.RSAKey.from_private_key_file(ssh_key_path)
        print(f"Successfully loaded private key. Fingerprint: {pkey.get_fingerprint().hex()}")
        print(f"Key type: {pkey.get_name()}")
        
        client.connect(
            hostname=ssh_host,
            port=ssh_port,
            username=ssh_user,
            pkey=pkey,
            look_for_keys=True,
            allow_agent=True,
            timeout=10
        )
        print("SUCCESS: Connection established!")
        stdin, stdout, stderr = client.exec_command('ls -l')
        print("Output of 'ls -l':")
        print(stdout.read().decode())
        client.close()
    except Exception as e:
        print(f"FAILED: {e}")
        import traceback
        traceback.print_exc()

if __name__ == "__main__":
    test_ssh()
