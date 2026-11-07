#!/usr/bin/env python3
"""Package deployable code, excluding credentials, data and local development tools."""
from datetime import datetime
from pathlib import Path
import tarfile

root = Path(__file__).resolve().parents[1]
output = root / 'runtime' / ('uii-portal-code-' + datetime.now().strftime('%Y%m%d-%H%M%S') + '.tar.gz')
output.parent.mkdir(exist_ok=True)
def include(info):
    path = Path(info.name)
    parts = path.parts
    if info.issym() or info.islnk():
        return None
    if str(path) == 'app/config.php':
        return None
    if len(parts) > 2 and parts[1] in {'cache', 'files', 'store'}:
        if len(parts) != 3 or parts[2] not in {'.htaccess', 'index.htm'}:
            return None
    if parts[1:4] == ('images', 'avatars', 'upload') and len(parts) > 4:
        if parts[-1] not in {'.htaccess', 'index.htm'}:
            return None
    if parts[1:2] == ('docs',) and len(parts) > 2 and parts[-1] not in {'LICENSE.txt', 'CREDITS.txt'}:
        return None
    if str(path).startswith('app/ext/uii/portal/tests'):
        return None
    return info
with tarfile.open(output, 'w:gz', compresslevel=6) as archive:
    archive.add(root / 'app', arcname='app', filter=include)
output.chmod(0o600)
print(output)
print(f'{output.stat().st_size / 1024 / 1024:.1f} MiB; code only, no config.php, uploads or database')
