"""CI checks for a data-free, constrained web updater package."""
import hashlib,json,re,sys,zipfile
from pathlib import Path
archive=Path(sys.argv[1])
with zipfile.ZipFile(archive) as z:
 names=z.namelist();assert len(names)==len(set(n.lower() for n in names))
 manifest=json.loads(z.read('manifest.json'));assert manifest['format']==1 and manifest['product']=='uii/portal'
 assert set(names)=={'manifest.json'}|{'payload/'+p for p in manifest['files']}
 for path,metadata in manifest['files'].items():
  assert path.startswith(('ext/uii/portal/','styles/uii_modern/','language/ru/')) and '..' not in Path(path).parts
  assert Path(path).name not in ['config.php','.env'] and Path(path).suffix.lower() not in ['.sql','.sqlite','.sqlite3','.db','.gz','.zip','.phar','.tar']
  content=z.read('payload/'+path);assert len(content)==metadata['size'] and hashlib.sha256(content).hexdigest()==metadata['sha256']
 assert json.loads(z.read('payload/ext/uii/portal/composer.json'))['version']==manifest['version']
print('PASS web Release: exact manifest, SHA-256, version and protected data exclusion')
