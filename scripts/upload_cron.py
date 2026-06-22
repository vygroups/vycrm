import ftplib
import socket
import os

def custom_makepasv(self):
    if self.af == socket.AF_INET:
        try:
            host, port = ftplib.parse229(self.sendcmd('EPSV'), self.sock.getpeername())
            return host, port
        except Exception:
            resp = self.sendcmd('PASV')
            if resp.startswith('229'):
                host, port = ftplib.parse229(resp, self.sock.getpeername())
                return host, port
            untrusted_host, port = ftplib.parse227(resp)
            if self.trust_server_pasv_ipv4_address:
                host = untrusted_host
            else:
                host = self.sock.getpeername()[0]
            return host, port
    else:
        host, port = ftplib.parse229(self.sendcmd('EPSV'), self.sock.getpeername())
    return host, port

ftplib.FTP.makepasv = custom_makepasv

HOST = "147.93.99.228"
USER = "u495954467.vycrm"
PASS = "Tn02aps2391*"

ftp = ftplib.FTP(HOST, USER, PASS)
ftp.cwd("/")

file_path = "web/cron_campaigns.php"
with open(file_path, 'rb') as f:
    print(f"Uploading {file_path}")
    ftp.storbinary(f"STOR cron_campaigns.php", f)
print("Done!")
