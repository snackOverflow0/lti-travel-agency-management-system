# Local SMTP server for tests only (aiosmtpd). Stores each accepted message as a file.
import asyncio, os, sys, time
from aiosmtpd.controller import Controller
out = sys.argv[2] if len(sys.argv) > 2 else '/tmp/lti-smtp-sink'; os.makedirs(out, exist_ok=True)
class H:
    async def handle_DATA(self, server, session, envelope):
        with open(os.path.join(out, '%d-%d.eml' % (time.time_ns(), os.getpid())), 'wb') as f: f.write(envelope.content)
        return '250 Message accepted for delivery'
c = Controller(H(), hostname='127.0.0.1', port=int(sys.argv[1] if len(sys.argv) > 1 else 2525)); c.start()
try:
    while True: time.sleep(3600)
finally: c.stop()
