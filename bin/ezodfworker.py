#!/usr/bin/env python3
"""
ezodf conversion server: the supervisor of a long-lived headless LibreOffice.

It runs inside ezodf's sandbox (bubblewrap without network, see expOdfSandbox) with the Python of the LibreOffice
installation (the TDF build's program/python, or the system python3 with the uno module of libreoffice-pyuno), and
is started and stopped only by expOdfServer. It

  * starts soffice listening on a local UNO pipe inside the sandbox (no TCP port at all),
  * serves a spool directory: PHP writes <spool>/<job>/request.json and the input file; the supervisor converts the
    document and writes the output file and <spool>/<job>/result.json,
  * writes <state>/heartbeat.json every two seconds (pid, soffice pid, version, conversions, uptime, last error),
  * restarts soffice after a crash, after a conversion that ran past its timeout, and after N conversions,
  * stops when <state>/stop exists or on SIGTERM.

Requests are JSON: {"input": "in.docx", "output": "out.pdf", "filter": "writer_pdf_Export" (optional),
"infilter": "" (optional), "filter_options": "" (optional), "timeout": 90}. Paths are names inside the job directory;
anything else is refused. LibreOffice never runs macros (load property MacroExecutionMode=NEVER_EXECUTE and the
profile's macro security), never updates links (UpdateDocMode=NO_UPDATE) and opens documents hidden and read-only.

Modes:
  ezodfworker.py serve --spool DIR --state DIR --soffice PATH [--timeout 90] [--restart-after 200]
  ezodfworker.py check --soffice PATH            prints {"ok": true, "python": ..., "uno": true} as JSON

Copyright (C) 1998 - 2026 7x & Exponential Foundation. GNU General Public License v2.0 (or any later version).
"""
import json
import os
import signal
import subprocess
import sys
import threading
import time

VERSION = '7.0.2'

# the export filter of a target extension, by the kind of document LibreOffice opened
EXPORT_FILTERS = {
    'text': {
        'odt': 'writer8', 'docx': 'MS Word 2007 XML', 'doc': 'MS Word 97', 'rtf': 'Rich Text Format',
        'pdf': 'writer_pdf_Export', 'html': 'XHTML Writer File', 'xhtml': 'XHTML Writer File',
        'txt': 'Text (encoded)', 'epub': 'EPUB', 'fodt': 'OpenDocument Text Flat XML', 'png': 'writer_png_Export',
    },
    'spreadsheet': {
        'ods': 'calc8', 'xlsx': 'Calc MS Excel 2007 XML', 'xls': 'MS Excel 97', 'pdf': 'calc_pdf_Export',
        'csv': 'Text - txt - csv (StarCalc)', 'html': 'XHTML Calc File', 'xhtml': 'XHTML Calc File',
        'fods': 'OpenDocument Spreadsheet Flat XML', 'png': 'calc_png_Export',
    },
    'presentation': {
        'odp': 'impress8', 'pptx': 'Impress MS PowerPoint 2007 XML', 'ppt': 'MS PowerPoint 97',
        'pdf': 'impress_pdf_Export', 'html': 'impress_html_Export', 'png': 'impress_png_Export',
        'svg': 'impress_svg_Export', 'fodp': 'OpenDocument Presentation Flat XML',
    },
    'drawing': {
        'odg': 'draw8', 'pdf': 'draw_pdf_Export', 'png': 'draw_png_Export', 'svg': 'draw_svg_Export',
        'html': 'draw_html_Export',
    },
}
# a text document opened from these becomes an ODT through Writer; a web document is opened as text
SERVICES = (
    ('com.sun.star.presentation.PresentationDocument', 'presentation'),
    ('com.sun.star.sheet.SpreadsheetDocument', 'spreadsheet'),
    ('com.sun.star.drawing.DrawingDocument', 'drawing'),
    ('com.sun.star.text.GenericTextDocument', 'text'),
    ('com.sun.star.text.TextDocument', 'text'),
)

MACRO_PROFILE = """<?xml version="1.0" encoding="UTF-8"?>
<oor:items xmlns:oor="http://openoffice.org/2001/registry" xmlns:xs="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
<item oor:path="/org.openoffice.Office.Common/Security/Scripting"><prop oor:name="MacroSecurityLevel" oor:op="fuse"><value>3</value></prop></item>
<item oor:path="/org.openoffice.Office.Common/Security/Scripting"><prop oor:name="DisableMacrosExecution" oor:op="fuse"><value>true</value></prop></item>
<item oor:path="/org.openoffice.Office.Common/Misc"><prop oor:name="FirstRun" oor:op="fuse"><value>false</value></prop></item>
<item oor:path="/org.openoffice.Office.Common/Misc"><prop oor:name="UseLocking" oor:op="fuse"><value>false</value></prop></item>
<item oor:path="/org.openoffice.Office.Common/Save/Document"><prop oor:name="CreateBackup" oor:op="fuse"><value>false</value></prop></item>
<item oor:path="/org.openoffice.Office.Recovery/AutoSave"><prop oor:name="Enabled" oor:op="fuse"><value>false</value></prop></item>
<item oor:path="/org.openoffice.Office.Recovery/RecoveryInfo"><prop oor:name="Enabled" oor:op="fuse"><value>false</value></prop></item>
<item oor:path="/org.openoffice.Office.Common/Internet/Proxy"><prop oor:name="ooInetProxyType" oor:op="fuse"><value>0</value></prop></item>
<item oor:path="/org.openoffice.Office.Common/Security"><prop oor:name="BlockUntrustedRefererLinks" oor:op="fuse"><value>true</value></prop></item>
</oor:items>
"""


def write_json(path, data):
    tmp = path + '.tmp'
    with open(tmp, 'w', encoding='utf-8') as fh:
        json.dump(data, fh)
    os.replace(tmp, path)


def log(state, message):
    path = os.path.join(state, 'server.log')
    try:
        if os.path.exists(path) and os.path.getsize(path) > 1048576:
            os.replace(path, path + '.1')
        with open(path, 'a', encoding='utf-8') as fh:
            fh.write(time.strftime('%Y-%m-%d %H:%M:%S') + ' ' + message + '\n')
    except OSError:
        pass


def prepare_profile(profile):
    user = os.path.join(profile, 'user')
    os.makedirs(user, exist_ok=True)
    xcu = os.path.join(user, 'registrymodifications.xcu')
    if not os.path.exists(xcu):
        with open(xcu, 'w', encoding='utf-8') as fh:
            fh.write(MACRO_PROFILE)


class Office:
    """One soffice process and the UNO connection to it."""

    def __init__(self, soffice, state, pipe):
        self.soffice = soffice
        self.state = state
        self.pipe = pipe
        self.proc = None
        self.desktop = None
        self.version = ''
        self.conversions = 0

    def start(self):
        import uno  # noqa: F401  (the installation's own module)
        profile = os.path.join(self.state, 'profile')
        prepare_profile(profile)
        args = [self.soffice, '--headless', '--invisible', '--norestore', '--nologo', '--nodefault', '--nolockcheck',
                '--nofirststartwizard', '-env:UserInstallation=' + uno.systemPathToFileUrl(profile),
                '--accept=pipe,name=%s;urp;StarOffice.ComponentContext' % self.pipe]
        self.proc = subprocess.Popen(args, stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        local = uno.getComponentContext()
        resolver = local.ServiceManager.createInstanceWithContext('com.sun.star.bridge.UnoUrlResolver', local)
        deadline = time.time() + 60
        while True:
            try:
                ctx = resolver.resolve('uno:pipe,name=%s;urp;StarOffice.ComponentContext' % self.pipe)
                break
            except Exception:
                if self.proc.poll() is not None or time.time() > deadline:
                    raise RuntimeError('soffice did not start (exit code %s)' % self.proc.poll())
                time.sleep(0.25)
        self.desktop = ctx.ServiceManager.createInstanceWithContext('com.sun.star.frame.Desktop', ctx)
        try:
            provider = ctx.ServiceManager.createInstanceWithContext('com.sun.star.configuration.ConfigurationProvider', ctx)
            node = provider.createInstanceWithArguments('com.sun.star.configuration.ConfigurationAccess',
                                                        (prop('nodepath', '/org.openoffice.Setup/Product'),))
            self.version = '%s %s' % (node.getByName('ooName'), node.getByName('ooSetupVersionAboutBox'))
        except Exception:
            self.version = ''
        self.conversions = 0
        log(self.state, 'soffice started, pid %d, %s' % (self.proc.pid, self.version))

    def alive(self):
        return self.proc is not None and self.proc.poll() is None

    def stop(self):
        if self.desktop is not None:
            try:
                self.desktop.terminate()
            except Exception:
                pass
        self.desktop = None
        if self.proc is not None:
            try:
                self.proc.wait(5)
            except Exception:
                self.proc.kill()
                try:
                    self.proc.wait(5)
                except Exception:
                    pass
        self.proc = None

    def kill(self):
        if self.proc is not None:
            try:
                self.proc.kill()
                self.proc.wait(5)
            except Exception:
                pass
        self.proc = None
        self.desktop = None

    def convert(self, source, target, filter_name, infilter, filter_options):
        import uno
        load = [prop('Hidden', True), prop('ReadOnly', True), prop('MacroExecutionMode', 0), prop('UpdateDocMode', 0),
                prop('RepairPackage', False)]
        if infilter:
            load.append(prop('FilterName', infilter))
        doc = self.desktop.loadComponentFromURL(uno.systemPathToFileUrl(source), '_blank', 0, tuple(load))
        if doc is None:
            raise RuntimeError('LibreOffice could not open the document')
        try:
            kind = 'text'
            for service, name in SERVICES:
                if doc.supportsService(service):
                    kind = name
                    break
            ext = os.path.splitext(target)[1].lstrip('.').lower()
            if not filter_name:
                filter_name = EXPORT_FILTERS.get(kind, {}).get(ext, '')
            if not filter_name:
                raise RuntimeError('no export filter for a %s document to .%s' % (kind, ext))
            store = [prop('FilterName', filter_name), prop('Overwrite', True)]
            if filter_options:
                store.append(prop('FilterOptions', filter_options))
            if hasattr(doc, 'refresh') and kind == 'text':
                try:
                    doc.refresh()
                except Exception:
                    pass
            doc.storeToURL(uno.systemPathToFileUrl(target), tuple(store))
            return {'filter': filter_name, 'kind': kind}
        finally:
            try:
                doc.close(True)
            except Exception:
                try:
                    doc.dispose()
                except Exception:
                    pass


def prop(name, value):
    import uno
    from com.sun.star.beans import PropertyValue  # noqa: E402
    p = PropertyValue()
    p.Name = name
    p.Value = value
    return p


def safe_name(name):
    name = os.path.basename(str(name))
    return name if name and name not in ('.', '..') and not name.startswith('.') else ''


def serve(opts):
    spool, state = opts['spool'], opts['state']
    os.makedirs(spool, exist_ok=True)
    os.makedirs(state, exist_ok=True)
    stop_file = os.path.join(state, 'stop')
    if os.path.exists(stop_file):
        os.remove(stop_file)
    stopping = {'flag': False}

    def on_term(signum, frame):
        stopping['flag'] = True
    signal.signal(signal.SIGTERM, on_term)
    signal.signal(signal.SIGINT, on_term)

    office = Office(opts['soffice'], state, 'ezodf_' + str(os.getpid()))
    started = time.time()
    total = 0
    failures = 0
    last_error = ''
    last_beat = 0.0
    try:
        office.start()
    except Exception as e:
        last_error = 'start: %s' % e
        log(state, last_error)
    log(state, 'supervisor %s started, pid %d, spool %s' % (VERSION, os.getpid(), spool))

    def beat(status, job=''):
        write_json(os.path.join(state, 'heartbeat.json'), {
            'pid': os.getpid(), 'soffice_pid': office.proc.pid if office.alive() else 0, 'version': office.version,
            'worker': VERSION, 'status': status, 'job': job, 'conversions': total, 'failures': failures,
            'since_restart': office.conversions, 'started': int(started), 'time': int(time.time()),
            'last_error': last_error, 'timeout': opts['timeout'], 'restart_after': opts['restart_after']})

    while not stopping['flag'] and not os.path.exists(stop_file):
        if not office.alive():
            try:
                office.kill()
                office.start()
            except Exception as e:
                last_error = 'restart: %s' % e
                log(state, last_error)
                beat('starting')
                time.sleep(5)
                continue
        job = next_job(spool)
        if job is None:
            if time.time() - last_beat >= 2:
                beat('idle')
                last_beat = time.time()
            time.sleep(0.2)
            continue
        jobdir, request = job
        beat('converting', os.path.basename(jobdir))
        result = run_job(office, jobdir, request, opts)
        total += 1
        office.conversions += 1
        if not result.get('ok'):
            failures += 1
            last_error = result.get('error', '')
            log(state, 'job %s failed: %s' % (os.path.basename(jobdir), last_error))
        write_json(os.path.join(jobdir, 'result.json'), result)
        if result.get('restart') or office.conversions >= opts['restart_after']:
            log(state, 'restarting soffice after %d conversions%s' % (office.conversions, ' (' + result.get('error', '') + ')' if result.get('restart') else ''))
            if result.get('restart'):
                office.kill()
            else:
                office.stop()
        beat('idle')
        last_beat = time.time()
    beat('stopping')
    office.stop()
    log(state, 'supervisor stopped')
    beat('stopped')


def next_job(spool):
    try:
        names = sorted(os.listdir(spool))
    except OSError:
        return None
    for name in names:
        jobdir = os.path.join(spool, name)
        request = os.path.join(jobdir, 'request.json')
        taken = os.path.join(jobdir, 'request.taken')
        if not os.path.isfile(request):
            continue
        try:
            os.rename(request, taken)  # one supervisor takes a job
        except OSError:
            continue
        try:
            with open(taken, encoding='utf-8') as fh:
                return jobdir, json.load(fh)
        except Exception as e:
            write_json(os.path.join(jobdir, 'result.json'), {'ok': False, 'error': 'bad request: %s' % e, 'code': 'request'})
    return None


def run_job(office, jobdir, request, opts):
    source = safe_name(request.get('input', ''))
    target = safe_name(request.get('output', ''))
    if not source or not target or not os.path.isfile(os.path.join(jobdir, source)):
        return {'ok': False, 'error': 'the request names no input or output file', 'code': 'request'}
    timeout = min(int(request.get('timeout') or opts['timeout']), opts['timeout'] * 4)
    box = {}

    def work():
        try:
            box['info'] = office.convert(os.path.join(jobdir, source), os.path.join(jobdir, target),
                                         str(request.get('filter', '') or ''), str(request.get('infilter', '') or ''),
                                         str(request.get('filter_options', '') or ''))
        except Exception as e:  # a UNO error names the cause
            box['error'] = str(e) or e.__class__.__name__
    began = time.time()
    thread = threading.Thread(target=work, daemon=True)
    thread.start()
    thread.join(timeout)
    seconds = round(time.time() - began, 3)
    if thread.is_alive():
        office.kill()
        return {'ok': False, 'error': 'the conversion took longer than %d seconds' % timeout, 'code': 'timeout',
                'seconds': seconds, 'restart': True}
    if 'error' in box:
        crashed = not office.alive()
        return {'ok': False, 'error': box['error'], 'code': 'crashed' if crashed else 'convert', 'seconds': seconds,
                'restart': crashed}
    if not os.path.isfile(os.path.join(jobdir, target)):
        return {'ok': False, 'error': 'LibreOffice wrote no output', 'code': 'convert', 'seconds': seconds}
    info = box.get('info', {})
    return {'ok': True, 'output': target, 'filter': info.get('filter', ''), 'kind': info.get('kind', ''),
            'seconds': seconds, 'version': office.version, 'backend': 'server'}


def check(opts):
    out = {'ok': False, 'python': sys.version.split()[0], 'uno': False, 'worker': VERSION}
    try:
        import uno  # noqa: F401
        out['uno'] = True
        out['ok'] = True
    except Exception as e:
        out['error'] = str(e)
    print(json.dumps(out))
    return 0 if out['ok'] else 1


def parse(argv):
    if not argv or argv[0] not in ('serve', 'check'):
        sys.stderr.write(__doc__)
        sys.exit(2)
    opts = {'mode': argv[0], 'spool': '', 'state': '', 'soffice': '', 'timeout': 90, 'restart_after': 200}
    i = 1
    while i < len(argv):
        key = argv[i].lstrip('-').replace('-', '_')
        if key not in opts or i + 1 >= len(argv):
            sys.stderr.write('unknown option %s\n' % argv[i])
            sys.exit(2)
        value = argv[i + 1]
        opts[key] = int(value) if key in ('timeout', 'restart_after') else value
        i += 2
    opts['timeout'] = max(5, min(opts['timeout'], 3600))
    opts['restart_after'] = max(1, opts['restart_after'])
    return opts


if __name__ == '__main__':
    o = parse(sys.argv[1:])
    if o['mode'] == 'check':
        sys.exit(check(o))
    if not (o['spool'] and o['state'] and o['soffice']):
        sys.stderr.write('serve needs --spool, --state and --soffice\n')
        sys.exit(2)
    serve(o)
