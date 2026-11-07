#!/usr/bin/env python3
"""Build the constrained ZIP consumed by the in-site updater; never bundles live data."""
from pathlib import Path
import argparse,hashlib,json,re,stat,zipfile
ROOT=Path(__file__).resolve().parents[1]
def build(destination,repository,expected=None):
    if not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9-]{0,38}/[A-Za-z0-9_.-]{1,100}',repository):raise ValueError('Invalid repository')
    version=json.loads((ROOT/'app/ext/uii/portal/composer.json').read_text())['version']
    if not re.fullmatch(r'\d+\.\d+\.\d+',version) or (expected and expected.removeprefix('v')!=version):raise ValueError('Tag must match composer.json version')
    files={}
    for folder in ['ext/uii/portal','styles/uii_modern','language/ru']:
        for path in sorted((ROOT/'app'/folder).rglob('*')):
            if not path.is_file() or path.is_symlink() or 'tests' in path.parts:continue
            relative=path.relative_to(ROOT/'app').as_posix()
            if path.name in ['config.php','.env'] or path.suffix.lower() in ['.sql','.db','.sqlite','.sqlite3','.zip','.gz','.tar','.phar']:raise ValueError('Protected file: '+relative)
            files[relative]={'sha256':hashlib.sha256(path.read_bytes()).hexdigest(),'size':path.stat().st_size}
    if len(files)>4095 or sum(m['size'] for m in files.values())>67108864 or any(m['size']>8388608 for m in files.values()):raise ValueError('Web update exceeds installer size limits')
    manifest={'format':1,'product':'uii/portal','repository':repository,'version':version,'min_installed':'1.3.0','min_php':'8.1.0','files':files}
    destination.mkdir(parents=True,exist_ok=True)
    archive=destination/'uii-web-update.zip'
    with zipfile.ZipFile(archive,'w',zipfile.ZIP_DEFLATED) as z:
        for name,content in [('manifest.json',json.dumps(manifest,ensure_ascii=False,sort_keys=True,indent=2).encode())]+[('payload/'+relative,(ROOT/'app'/relative).read_bytes()) for relative in files]:
            info=zipfile.ZipInfo(name);info.create_system=3;info.external_attr=(stat.S_IFREG|0o644)<<16;info.compress_type=zipfile.ZIP_DEFLATED;z.writestr(info,content)
    if archive.stat().st_size>16777216:
        archive.unlink();raise ValueError('Compressed web update exceeds 16 MiB')
    (destination/'uii-web-update.sha256').write_text(hashlib.sha256(archive.read_bytes()).hexdigest()+'  '+archive.name+'\n')
    print(archive)
    return archive
if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('--repository',default='anrhangels/uii-forum');parser.add_argument('--output',type=Path,default=ROOT/'release/web-update');parser.add_argument('--expected-version');args=parser.parse_args()
    build(args.output,args.repository,args.expected_version)
