"""Уменьшенная копия каталога аватаров для стенда.

Канал до нового сервера — 148 КБ/с, 820 МБ оригиналов шли бы полтора часа.
Аватары на сайте показываются не крупнее 320×400 (шапка карточки), поэтому
для стенда достаточно копии с длинной стороной 1600 px. Имена файлов
сохраняются байт в байт: на них ссылается база. Формат тоже сохраняется,
иначе разошлись бы расширения. PDF, HEIC, GIF копируются как есть.
"""
import io
import os
import shutil
import sys

from PIL import Image, ImageOps

SRC = sys.argv[1]
DST = sys.argv[2]
MAX_SIDE = 1600
RASTER = {".jpg", ".jpeg", ".jfif", ".png", ".webp"}

total_in = total_out = shrunk = copied = failed = 0
for root, _dirs, files in os.walk(SRC):
    rel = os.path.relpath(root, SRC)
    out_dir = os.path.join(DST, rel) if rel != "." else DST
    os.makedirs(out_dir, exist_ok=True)
    for name in files:
        src = os.path.join(root, name)
        dst = os.path.join(out_dir, name)
        size = os.path.getsize(src)
        total_in += size
        ext = os.path.splitext(name)[1].lower()
        if ext not in RASTER or size < 300 * 1024:
            shutil.copy2(src, dst)
            total_out += size
            copied += 1
            continue
        try:
            im = Image.open(src)
            im = ImageOps.exif_transpose(im)
            im.thumbnail((MAX_SIDE, MAX_SIDE), Image.LANCZOS)
            buf = io.BytesIO()
            if ext in (".jpg", ".jpeg", ".jfif"):
                if im.mode not in ("RGB", "L"):
                    im = im.convert("RGB")
                im.save(buf, "JPEG", quality=82, optimize=True, progressive=True)
            elif ext == ".png":
                im.save(buf, "PNG", optimize=True)
            else:
                im.save(buf, "WEBP", quality=82, method=4)
            data = buf.getvalue()
            # уменьшенная версия не должна быть больше исходной
            if len(data) >= size:
                shutil.copy2(src, dst)
                total_out += size
                copied += 1
            else:
                with open(dst, "wb") as fh:
                    fh.write(data)
                shutil.copystat(src, dst)
                total_out += len(data)
                shrunk += 1
        except Exception as exc:  # noqa: BLE001 — битый файл копируем как есть
            print("как есть (не открылся):", name, exc)
            shutil.copy2(src, dst)
            total_out += size
            copied += 1
            failed += 1

print("исходно %.1f МБ, стало %.1f МБ; уменьшено %d, скопировано %d, не открылось %d"
      % (total_in / 2**20, total_out / 2**20, shrunk, copied, failed))
