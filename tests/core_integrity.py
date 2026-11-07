from pathlib import Path
import zipfile
archive=Path('downloads/phpBB-3.3.19.zip')
changed=[]
with zipfile.ZipFile(archive) as z:
 for item in z.infolist():
  if item.is_dir() or not item.filename.startswith('phpBB3/'):continue
  relative=item.filename[len('phpBB3/'):]
  if relative=='config.php' or relative.startswith('install/'):continue
  target=Path('app')/relative
  if not target.is_file() or target.read_bytes()!=z.read(item):changed.append(relative)
assert not changed, changed
print('PASS original phpBB core and prosilver unchanged')
