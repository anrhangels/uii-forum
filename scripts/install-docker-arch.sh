#!/usr/bin/env bash
set -euo pipefail
source /etc/os-release
[[ "$ID" == arch ]] || { echo 'Этот скрипт предназначен для Arch Linux'; exit 1; }
echo 'Будут обновлены системные пакеты (pacman -Syu), установлены Docker, Compose, curl, unzip и Python.'
echo 'Docker будет запущен до перезагрузки. Автозапуск и группы пользователей не изменяются.'
read -r -p 'Продолжить? [y/N] ' answer
[[ "$answer" == y || "$answer" == Y ]] || exit 0
sudo pacman -Syu --needed docker docker-compose curl unzip python
sudo systemctl start docker
sudo docker info >/dev/null
sudo docker compose version
echo 'Готово. Запустите: bash scripts/prepare-phpbb.sh'
