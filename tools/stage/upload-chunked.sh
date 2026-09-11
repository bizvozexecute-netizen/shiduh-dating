#!/usr/bin/env bash
# Устойчивая заливка большого файла по рвущемуся каналу: кусками с повторами,
# на той стороне склейка и сверка контрольной суммы.
#   up.sh <локальный файл> <хост ssh> <удалённый путь> [размер куска]
set -uo pipefail
SRC="$1"; HOST="$2"; DST="$3"; CHUNK="${4:-8m}"
NAME=$(basename "$SRC")
WORK="$(dirname "$SRC")/.parts_$NAME"
rm -rf "$WORK"; mkdir -p "$WORK"
split -b "$CHUNK" -d -a 4 "$SRC" "$WORK/p."
SUM=$(sha256sum "$SRC" | cut -d' ' -f1)
N=$(ls "$WORK" | wc -l)
ssh -o ConnectTimeout=20 "$HOST" "rm -rf /tmp/parts_$NAME && mkdir -p /tmp/parts_$NAME" || exit 1
i=0
for p in "$WORK"/p.*; do
  i=$((i+1)); b=$(basename "$p")
  for try in 1 2 3 4 5 6 7 8 9 10; do
    # кусок уже на месте и того же размера — пропускаем
    if [ "$(ssh -o ConnectTimeout=20 "$HOST" "stat -c %s /tmp/parts_$NAME/$b 2>/dev/null")" = "$(stat -c %s "$p")" ]; then
      break
    fi
    if scp -q -o ConnectTimeout=20 "$p" "$HOST:/tmp/parts_$NAME/$b"; then
      break
    fi
    echo "  кусок $i/$N: попытка $try не удалась, повтор через 5 с"; sleep 5
  done
  echo "  кусок $i/$N готов"
done
ssh -o ConnectTimeout=20 "$HOST" "cat /tmp/parts_$NAME/p.* > '$DST' && rm -rf /tmp/parts_$NAME && echo \"\$(sha256sum '$DST' | cut -d' ' -f1)\"" | grep -q "^$SUM$" \
  && { echo "ГОТОВО: $NAME доставлен, сумма совпала"; rm -rf "$WORK"; } \
  || { echo "ОШИБКА: сумма не совпала для $NAME"; exit 2; }
