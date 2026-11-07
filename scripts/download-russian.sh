#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
[[ "$EUID" != 0 ]] || { echo 'Запускайте без sudo'; exit 1; }
mkdir -p downloads
if [[ $# -gt 0 ]]; then
  test -f "$1" || { echo 'Указанный архив не найден'; exit 1; }
  unzip -tq "$1"
  cp -- "$1" downloads/russian-2.1.20.zip.part
else
  echo 'Скачивание русского пакета (не более 60 секунд)…'
  curl --fail --location --show-error --progress-bar --connect-timeout 10 --max-time 60 --speed-limit 1024 --speed-time 15 'https://www.phpbbguru.net/files/download-send/441/2/0' -o downloads/russian-2.1.20.zip.part
fi
unzip -tq downloads/russian-2.1.20.zip.part
mv downloads/russian-2.1.20.zip.part downloads/russian-2.1.20.zip
python3 - <<'PY'
from pathlib import Path
import zipfile
root=Path('app/language/ru')
if root.exists():
    raise SystemExit('Русский пакет уже существует; перезапись отменена')
with zipfile.ZipFile('downloads/russian-2.1.20.zip') as z:
    entries=[]
    for item in z.infolist():
        parts=Path(item.filename).parts
        if 'language' not in parts: continue
        i=parts.index('language')
        rel=Path(*parts[i:])
        if '..' in rel.parts or rel.is_absolute(): raise SystemExit('Небезопасный путь в архиве')
        if len(rel.parts)<2 or rel.parts[1]!='ru': continue
        entries.append((item,Path('app')/rel))
    if not any(str(path).endswith('/ru/common.php') for _,path in entries):
        raise SystemExit('Структура архива не распознана')
    for item,path in entries:
        if item.is_dir(): path.mkdir(parents=True,exist_ok=True)
        else:
            path.parent.mkdir(parents=True,exist_ok=True)
            path.write_bytes(z.read(item))
print('Языковые файлы скопированы. Установите Russian через Customise → Language packs.')
PY
