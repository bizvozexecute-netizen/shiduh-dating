# -*- coding: utf-8 -*-
"""
C5 этапа «До идеала»: лимиты загрузок анкеты.

Поля анкеты принимали файлы любого типа и размера, а тип ядро проверяло по
MIME, который заявил клиент: переименованный x.php с Content-Type image/png
проходил. Теперь: лимит размера и типа в формате поля (миграция 024),
серверная проверка реальных байт после сохранения (upload_guard_lib.php) и
запрет исполнения чего бы то ни было в netcat_files/ (.htaccess).

Тест правит анкету кандидата 50 (Message_ID 42, файлов у неё нет) частичной
правкой (partial=1): в POST уходят только служебные поля формы и один файл.
Файлы и поля возвращаются в исходное состояние в teardown.

    python -m pytest tests/upload_limits_test.py -q
"""
import io
import os
import re
import struct
import sys
import urllib.error
import urllib.parse
import urllib.request
import uuid
import zlib

import pytest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from fake_llm import BASE, NoRedirect, dbq, get_text, login  # noqa: E402

CANDIDATE = "u091ff2b36c83@example.local"   # User 50, анкета 42
ANKETA = 42
EDIT_PAGE = "/my/modify/"
ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..")
FILES_DIR = os.path.join(ROOT, "shiduh.org", "netcat_files")
FIELDS = ("Photo_avatar", "Birthcertificates", "Another_document_Jewishness")

_SID = {}
_SAVED = {}
_CREATED = []          # относительные пути файлов, которые тест мог создать


def sid():
    if "s" not in _SID:
        _SID["s"] = login(CANDIDATE)
    return _SID["s"]


def field(name):
    return dbq("SELECT IFNULL(`%s`,'') FROM `Message204` WHERE Message_ID=%d" % (name, ANKETA))


def png_bytes(size=None):
    """Настоящий PNG 1×1; size — добить нулями после IEND до нужного размера."""
    def chunk(typ, data):
        return (struct.pack(">I", len(data)) + typ + data
                + struct.pack(">I", zlib.crc32(typ + data) & 0xffffffff))
    ihdr = struct.pack(">IIBBBBB", 1, 1, 8, 2, 0, 0, 0)
    idat = zlib.compress(b"\x00\xff\x00\x00")
    data = b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", ihdr) + chunk(b"IDAT", idat) + chunk(b"IEND", b"")
    if size and size > len(data):
        data += b"\x00" * (size - len(data))
    return data


def pdf_bytes():
    return b"%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n"


def form_fields():
    """Служебные поля формы правки — только со страницы (nc_token в том числе)."""
    code, html = get_text(EDIT_PAGE, sid())
    assert code == 200, "форма правки не открылась: %d" % code
    m = re.search(r'<form[^>]*id="adminForm".*?</form>', html, re.S)
    assert m, "на странице нет формы adminForm"
    form = m.group(0)
    fields = {}
    for name, value in re.findall(r"<input[^>]*name='([^']+)'[^>]*value='([^']*)'", form):
        fields[name] = value
    for name, value in re.findall(r'<input[^>]*type="hidden"[^>]*name="([^"]+)"[^>]*value="([^"]*)"', form):
        fields[name] = value
    for need in ("nc_token", "catalogue", "cc", "sub", "message", "posting", "partial"):
        assert need in fields, "в форме нет %s: %s" % (need, sorted(fields)[:20])
    assert fields["message"] == str(ANKETA), "форма правки не той анкеты: " + fields["message"]
    return {k: fields[k] for k in ("admin_mode", "nc_token", "catalogue", "cc", "sub", "message",
                                   "posting", "curPos", "f_Parent_Message_ID", "partial") if k in fields}


def upload(field_name, filename, data, ctype):
    fields = form_fields()
    boundary = uuid.uuid4().hex
    buf = io.BytesIO()
    for k, v in fields.items():
        buf.write(("--%s\r\nContent-Disposition: form-data; name=\"%s\"\r\n\r\n%s\r\n"
                   % (boundary, k, v)).encode())
    buf.write(("--%s\r\nContent-Disposition: form-data; name=\"f_%s\"; filename=\"%s\"\r\n"
               "Content-Type: %s\r\n\r\n" % (boundary, field_name, filename, ctype)).encode())
    buf.write(data)
    buf.write(("\r\n--%s--\r\n" % boundary).encode())
    req = urllib.request.Request(BASE + "/netcat/message.php", data=buf.getvalue(), method="POST")
    req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % sid())
    req.add_header("Content-Type", "multipart/form-data; boundary=" + boundary)
    req.add_header("Origin", BASE)
    opener = urllib.request.build_opener(NoRedirect)
    try:
        r = opener.open(req, timeout=180)
        return r.status, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")


def stored_path(value):
    parts = value.split(":")
    return parts[3] if len(parts) >= 4 else ""


def file_exists(rel):
    return bool(rel) and os.path.isfile(os.path.join(FILES_DIR, rel.replace("/", os.sep)))


def clear_field(name):
    rel = stored_path(field(name))
    if rel and file_exists(rel):
        os.remove(os.path.join(FILES_DIR, rel.replace("/", os.sep)))
    dbq("UPDATE `Message204` SET `%s`='' WHERE Message_ID=%d" % (name, ANKETA))


def setup_module(module):
    for f in FIELDS:
        _SAVED[f] = field(f)
        assert _SAVED[f] == "", "у анкеты %d уже есть файл в %s — фикстура не та" % (ANKETA, f)


def teardown_module(module):
    for f in FIELDS:
        clear_field(f)


# ------------------------------------------------------------- принимается ---

def test_small_png_avatar_is_accepted():
    clear_field("Photo_avatar")
    code, html = upload("Photo_avatar", "me.png", png_bytes(100 * 1024), "image/png")
    assert code in (200, 302, 303), "правка не прошла: %d" % code
    value = field("Photo_avatar")
    assert value.startswith("me.png:image/png:"), "аватар не сохранён: " + value
    assert file_exists(stored_path(value)), "файла нет на диске"


def test_pdf_certificate_is_accepted():
    clear_field("Birthcertificates")
    code, html = upload("Birthcertificates", "cert.pdf", pdf_bytes(), "application/pdf")
    assert code in (200, 302, 303), code
    value = field("Birthcertificates")
    assert value.startswith("cert.pdf:application/pdf:"), "PDF-свидетельство не сохранено: " + value
    assert file_exists(stored_path(value))


# ------------------------------------------------------------ отклоняется ---

def test_oversized_avatar_is_rejected():
    """9 МБ при пределе 8 — отказ ядра по формату поля (миграция 024)."""
    clear_field("Photo_avatar")
    code, html = upload("Photo_avatar", "big.png", png_bytes(9 * 1024 * 1024), "image/png")
    assert field("Photo_avatar") == "", "файл больше предела сохранён: " + field("Photo_avatar")


def test_pdf_disguised_as_png_is_dropped():
    """Заявлено image/png, внутри PDF: ядро верит заявке, серверная проверка
    байт — нет. Файл удалён, поле пустое."""
    clear_field("Photo_avatar")
    code, html = upload("Photo_avatar", "photo.png", pdf_bytes(), "image/png")
    assert field("Photo_avatar") == "", "PDF под видом картинки сохранён как аватар"


def test_text_edit_keeps_existing_file():
    """Правка одного текстового поля НЕ должна трогать уже загруженный файл:
    хук проверяет только то, что пришло в этом запросе (иначе стёр бы старые
    документы — спецкатегорию ПДн)."""
    clear_field("Birthcertificates")
    upload("Birthcertificates", "cert.pdf", pdf_bytes(), "application/pdf")
    value = field("Birthcertificates")
    assert value.startswith("cert.pdf:"), "предусловие: PDF не сохранён"
    rel = stored_path(value)
    # правим текстовое поле без файла
    fields = form_fields()
    fields["f_About_self"] = "правка без файла 71717"
    body = urllib.parse.urlencode(fields).encode()
    req = urllib.request.Request(BASE + "/netcat/message.php", data=body, method="POST")
    req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % sid())
    req.add_header("Content-Type", "application/x-www-form-urlencoded")
    req.add_header("Origin", BASE)
    try:
        urllib.request.build_opener(NoRedirect).open(req, timeout=120)
    except urllib.error.HTTPError:
        pass                       # 302 после сохранения — ожидаемо
    assert field("Birthcertificates") == value, "файл-документ обнулён при правке текста"
    assert file_exists(rel), "файл-документ удалён с диска при правке текста"


def test_php_disguised_as_png_is_dropped_and_not_served():
    clear_field("Photo_avatar")
    payload = b"<?php echo 'pwned'; ?>"
    code, html = upload("Photo_avatar", "x.php", payload, "image/png")
    assert field("Photo_avatar") == "", "PHP-файл сохранён в анкете: " + field("Photo_avatar")
    # даже если бы файл остался — .htaccess не отдаёт исполняемые расширения
    probe = os.path.join(FILES_DIR, "_c5_probe.php")
    with open(probe, "wb") as fh:
        fh.write(payload)
    try:
        code, body = get_text("/netcat_files/_c5_probe.php", None)
        assert code == 403, "исполняемый файл в netcat_files отдан с кодом %d" % code
        assert "pwned" not in body, "PHP в netcat_files ИСПОЛНЯЕТСЯ"
        # двойное расширение — тоже отказ
        probe2 = os.path.join(FILES_DIR, "_c5_probe.php.png")
        with open(probe2, "wb") as fh2:
            fh2.write(payload)
        try:
            code2, _ = get_text("/netcat_files/_c5_probe.php.png", None)
            assert code2 == 403, "двойное расширение .php.png отдано: %d" % code2
        finally:
            os.remove(probe2)
    finally:
        os.remove(probe)


def test_polyglot_png_with_php_extension_dropped():
    """Валидный PNG, но имя x.phtml: getimagesize проходит, а расширение
    исполняемое — файл обязан быть отвергнут и удалён с диска."""
    clear_field("Photo_avatar")
    poly = png_bytes() + b"<?php echo 'x'; ?>"
    code, html = upload("Photo_avatar", "x.phtml", poly, "image/png")
    assert field("Photo_avatar") == "", "PNG под именем .phtml принят: " + field("Photo_avatar")


def test_pdf_avatar_rejected_by_format():
    """Аватар — только изображение: PDF с честным MIME отклоняет уже ядро."""
    clear_field("Photo_avatar")
    upload("Photo_avatar", "me.pdf", pdf_bytes(), "application/pdf")
    assert field("Photo_avatar") == "", "PDF принят в качестве аватара"


def test_static_image_still_served():
    """Запрет исполнения не должен задеть обычные картинки."""
    probe = os.path.join(FILES_DIR, "_c5_probe.png")
    with open(probe, "wb") as fh:
        fh.write(png_bytes())
    try:
        req = urllib.request.Request(BASE + "/netcat_files/_c5_probe.png")
        with urllib.request.urlopen(req, timeout=60) as r:
            assert r.status == 200
            assert r.headers.get("X-Content-Type-Options") == "nosniff", "нет nosniff на файлах"
    finally:
        os.remove(probe)


def test_avatar_accept_offers_only_images():
    """Форма больше не предлагает .pdf для аватара — и во всех копиях шаблона
    (Class.html — источник склейки, RecordTemplate — часть)."""
    import glob
    files = (glob.glob(os.path.join(ROOT, "shiduh.org", "netcat_template", "class", "204", "**", "*.html"), recursive=True)
             + glob.glob(os.path.join(ROOT, "shiduh.org", "netcat_template", "class", "netcat_module_auth_user", "**", "*.html"), recursive=True)
             + [os.path.join(ROOT, "shiduh.org", "api", "anketa_form.inc.php")])  # A7: общая форма анкеты
    seen = 0
    for path in files:
        src = open(path, encoding="utf-8").read()
        for accept in re.findall(r'name="f_Photo_avatar" type="file" accept="([^"]*)"', src):
            seen += 1
            assert ".pdf" not in accept, path + ": аватар всё ещё предлагает PDF"
    assert seen >= 3, "полей аватара с accept подозрительно мало: %d" % seen
