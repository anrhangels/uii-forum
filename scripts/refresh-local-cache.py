"""Сохранить кэш перед пересборкой веб-контейнером, без удаления файлов."""
from pathlib import Path
import datetime,shutil
root=Path(__file__).resolve().parent.parent
cache=root/'app/cache'
target=root/'snapshots'/('cache-'+datetime.datetime.now().strftime('%Y%m%d-%H%M%S-%f'))
cache.rename(target);cache.mkdir(mode=0o777);cache.chmod(0o777)
for name in ['.htaccess','index.htm']:
 if (target/name).is_file():shutil.copy2(target/name,cache/name)
print('Кэш сохранён:',target.name)
