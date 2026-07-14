import ftplib
import socket

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

HOST = "vycrm.vygroups.com"
USER = "u495954467.vycrm"
PASS = "Tn02aps2391*"

ftp = ftplib.FTP()
try:
    ftp.af = socket.AF_INET6
    ftp.connect(HOST, 21, timeout=10)
except Exception:
    ftp = ftplib.FTP()
    ftp.af = socket.AF_INET
    ftp.connect(HOST, 21, timeout=10)
ftp.login(USER, PASS)
ftp.cwd("/")

file_path = "web/check_db.php"
with open(file_path, 'rb') as f:
    ftp.storbinary(f"STOR check_db.php", f)
