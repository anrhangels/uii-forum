"""Isolated real HTTP uploads; uses temporary storage, never the portal DB."""
import base64
import json
import os
from pathlib import Path
import socket
import subprocess
import tempfile
import time
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
PHP_IMAGE = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aB1sAAAAASUVORK5CYII=')
with tempfile.TemporaryDirectory(prefix='uii-upload-') as directory:
    router = Path(directory) / 'router.php'
    # Exercise phpBB's actual request normalization, including its upload error type.
    router.write_text('''<?php
define('IN_PHPBB', true);
require getenv('UII_SOURCE').'/app/vendor/autoload.php';
require getenv('UII_SOURCE').'/app/includes/utf/utf_tools.php';
require getenv('UII_SOURCE').'/app/ext/uii/portal/service/evidence.php';
require getenv('UII_SOURCE').'/app/ext/uii/portal/service/case_schema.php';
require getenv('UII_SOURCE').'/app/ext/uii/portal/service/image_optimizer.php';
require getenv('UII_SOURCE').'/app/phpbb/request/request_interface.php';
require getenv('UII_SOURCE').'/app/phpbb/request/type_cast_helper_interface.php';
require getenv('UII_SOURCE').'/app/phpbb/request/type_cast_helper.php';
require getenv('UII_SOURCE').'/app/phpbb/request/request.php';
$request = new \\phpbb\\request\\request(null, false);
$storage = new \\uii\\portal\\service\\evidence(__DIR__);
header('Content-Type: application/json');
try { echo json_encode($storage->store($request->file('photo'))); }
catch (InvalidArgumentException $e) { http_response_code(400); echo json_encode(['error'=>$e->getMessage()]); }
''')
    with socket.socket() as listener:
        listener.bind(('127.0.0.1', 0))
        port = listener.getsockname()[1]
    with tempfile.TemporaryFile() as log:
        gd_args=['-d','extension='+os.environ['UII_TEST_GD']] if os.environ.get('UII_TEST_GD') else []
        server = subprocess.Popen(['bash', 'scripts/php-local.sh', *gd_args, '-d', 'upload_max_filesize=5M', '-d', 'post_max_size=20M', '-S', f'127.0.0.1:{port}', str(router)], cwd=ROOT, env={**os.environ, 'UII_SOURCE': str(ROOT)}, stdout=log, stderr=log)
        try:
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=.1):
                        break
                except OSError:
                    if server.poll() is not None:
                        log.seek(0)
                        raise RuntimeError(log.read().decode())
                    time.sleep(.03)
            def upload(contents, name='photo.png', mime='image/png'):
                boundary = 'uii-test-upload-boundary'
                body = (f'--{boundary}\r\nContent-Disposition: form-data; name="photo"; filename="{name}"\r\nContent-Type: {mime}\r\n\r\n'.encode() + contents + f'\r\n--{boundary}--\r\n'.encode())
                req = urllib.request.Request(f'http://127.0.0.1:{port}/', data=body, headers={'Content-Type': f'multipart/form-data; boundary={boundary}'})
                try:
                    with urllib.request.urlopen(req) as response:
                        return response.status, json.load(response)
                except urllib.error.HTTPError as response:
                    return response.code, json.load(response)
            status, data = upload(PHP_IMAGE, 'proof.png', 'image/png')
            assert status == 200 and data['mime'] == 'image/png', data
            stored = Path(directory) / 'files/uii_evidence' / (data['token'] + '.bin')
            assert stored.read_bytes() == PHP_IMAGE
            assert (stored.parent / '.htaccess').read_text() == 'Require all denied\n'
            print('PASS real PHP upload through phpBB request, original bytes, safe random filename and access file')
            for content, name in [(b'<?php echo "bad";', 'image.jpg'), (b'<svg></svg>', 'image.svg'), (b'x' * (5242880 + 1), 'large.png')]:
                status, data = upload(content, name)
                assert status == 400 and 'error' in data, data
            assert len(list(stored.parent.glob('*.bin'))) == 1
            print('PASS fake image, SVG and oversized file rejected without stored files')
            if gd_args:
                image=subprocess.check_output(['bash','scripts/php-local.sh',*gd_args,'-r', '$im=imagecreatetruecolor(1000,500);imagefill($im,0,0,imagecolorallocate($im,40,70,100));imagepng($im,null,0);'],cwd=ROOT)
                status,data=upload(image)
                compressed=stored.parent/(data['token']+'.bin')
                assert status==200 and data['optimized'] and data['original_size']==len(image) and data['size']==compressed.stat().st_size and data['size']<len(image),data
                assert compressed.read_bytes().startswith(b'\x89PNG'),data
                print('PASS real GD upload: atomic optimization, uploaded-file provenance, format and quota metadata; '+str(len(image))+' -> '+str(data['size'])+' bytes')
            if gd_args:
                for extension, mime, encoder in [('jpg','image/jpeg','imagejpeg'),('webp','image/webp','imagewebp')]:
                    content=subprocess.check_output(['bash','scripts/php-local.sh',*gd_args,'-r', '$im=imagecreatetruecolor(64,48);'+encoder+'($im);'],cwd=ROOT)
                    status,data=upload(content,'proof.'+extension,mime)
                    assert status==200 and data['mime']==mime,data
                print('PASS real JPEG and WebP HTTP uploads with MIME verification')
            quota = stored.parent / ('b' * 32 + '.bin')
            with quota.open('wb') as handle:
                handle.truncate(524288000)
            status, data = upload(PHP_IMAGE)
            assert status == 400 and '500' in data['error'], data
            print('PASS server storage quota enforced')
        finally:
            server.terminate()
            server.wait(timeout=5)
