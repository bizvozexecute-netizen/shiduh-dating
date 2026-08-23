# -*- coding: utf-8 -*-
"""
Тесты ограничения вложений в личных сообщениях (ROADMAP Ф-2).

Фича отправки фото кандидат↔шадхан уже работает (штатный /netcat/add.php,
поле image = Field_ID 648, тип multifile; файлы в таблице Multifield).
Миграция 009 ограничила поле: размер ≤10 МБ и только изображения.
Серверную проверку делает nc_multifield_saver (finfo по реальным байтам).

Тесты гоняют реальную отправку сообщения с вложением как браузер:
  - валидный PNG принимается (строка в Multifield появляется)
  - не-изображение (PDF-байты, даже с именем .png) отклоняется
  - файл больше лимита отклоняется
Во всех случаях само текстовое сообщение сохраняется — режется только файл.

    python -m pytest tests/photo_message_test.py -q
"""
import hashlib
import io
import os
import re
import struct
import subprocess
import urllib.error
import urllib.parse
import urllib.request
import uuid
import zlib

BASE = "http://localhost:8080"
DOCKER = r"C:\Program Files\Docker\Docker\resources\bin\docker.exe"
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PHOTO_FIELD_ID = 648
SHADHAN = "u3f9bb8c20b01@example.local"   # User_ID 57
DIALOGUE = "/my/message/soobscheniya_39508.html"

STATE = {}


def dbq(sql):
    r = subprocess.run(
        [DOCKER, "compose", "exec", "-T", "db", "mysql", "-uroot", "-proot_local_dev",
         "-N", "-B", "--default-character-set=utf8", "shiduh_org", "-e", sql],
        cwd=ROOT, capture_output=True, text=True, encoding="utf-8", errors="replace", timeout=60,
    )
    return (r.stdout or "").strip()


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **kw):
        return None


def login(email, pw="shiduh"):
    body = urllib.parse.urlencode({
        "AuthPhase": "1", "REQUESTED_FROM": "/my/", "REQUESTED_BY": "GET",
        "catalogue": "1", "sub": "8", "cc": "0", "AUTH_USER": email, "AUTH_PW": pw,
    }).encode()
    req = urllib.request.Request(BASE + "/netcat/modules/auth/", data=body, method="POST")
    try:
        h = urllib.request.build_opener(NoRedirect).open(req, timeout=60).headers
    except urllib.error.HTTPError as e:
        h = e.headers
    for raw in h.get_all("Set-Cookie") or []:
        m = re.search(r"PHP_AUTH_SID=([^;]+)", raw)
        if m:
            return m.group(1)
    raise RuntimeError("login failed: " + email)


def get(path, sid):
    req = urllib.request.Request(BASE + path)
    req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % sid)
    return urllib.request.urlopen(req, timeout=120).read().decode("utf-8", "replace")


def settings_hash():
    # nc_multifield_settings::get_setting_hash: use_name + path + preview_w/h/mode
    # + resize_w/h/mode + min + max + SecretKey. Все настройки виджета = 0/''.
    secret = dbq("SELECT `Value` FROM `Settings` WHERE `Key`='SecretKey'")
    return hashlib.md5(("00000000" + secret).encode()).hexdigest()


def png_bytes(pad=0):
    def chunk(typ, data):
        return (struct.pack(">I", len(data)) + typ + data
                + struct.pack(">I", zlib.crc32(typ + data) & 0xffffffff))
    ihdr = struct.pack(">IIBBBBB", 1, 1, 8, 2, 0, 0, 0)
    idat = zlib.compress(b"\x00\xff\x00\x00")
    data = b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", ihdr) + chunk(b"IDAT", idat) + chunk(b"IEND", b"")
    if pad:
        data += b"\x00" * pad   # мусор после IEND — finfo всё равно видит PNG по заголовку
    return data


def form_fields(sid):
    html = get(DIALOGUE, sid)
    m = re.search(r"<form id='write_message-new'.*?</form>", html, re.S)
    if not m:
        raise RuntimeError("форма отправки не найдена")
    form = m.group(0)
    fields = dict(re.findall(r"name='([^']+)'[^>]*value='([^']*)'", form))
    fields.update(dict(re.findall(r'name="([^"]+)"[^>]*value="([^"]*)"', form)))
    fields["settings_image_hash"] = settings_hash()  # под SecretKey стенда
    return fields


def send_message(sid, text, filename, filedata, ctype):
    fields = form_fields(sid)
    boundary = uuid.uuid4().hex
    buf = io.BytesIO()

    def add_field(name, value):
        buf.write(("--%s\r\nContent-Disposition: form-data; name=\"%s\"\r\n\r\n%s\r\n"
                   % (boundary, name, value)).encode())

    for k, v in fields.items():
        add_field(k, v)
    add_field("f_Message", text)
    buf.write(("--%s\r\nContent-Disposition: form-data; name=\"f_image_file[]\"; "
               "filename=\"%s\"\r\nContent-Type: %s\r\n\r\n" % (boundary, filename, ctype)).encode())
    buf.write(filedata)
    buf.write(("\r\n--%s--\r\n" % boundary).encode())

    req = urllib.request.Request(BASE + "/netcat/add.php", data=buf.getvalue(), method="POST")
    req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % sid)
    req.add_header("Content-Type", "multipart/form-data; boundary=" + boundary)
    try:
        r = urllib.request.urlopen(req, timeout=180)
        return r.status, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")


def find_message(text):
    return dbq("SELECT Message_ID FROM `Message6` WHERE Message=" + sql_str(text) + " LIMIT 1")


def multifile_count(msg_id):
    if not msg_id:
        return 0
    return int(dbq("SELECT COUNT(*) FROM `Multifield` WHERE Field_ID=%d AND Message_ID=%s"
                   % (PHOTO_FIELD_ID, msg_id)) or 0)


def sql_str(s):
    return "'" + s.replace("\\", "\\\\").replace("'", "''") + "'"


def cleanup(marker_like):
    ids = dbq("SELECT Message_ID FROM `Message6` WHERE Message LIKE '%s'" % marker_like).split()
    for mid in ids:
        dbq("DELETE FROM `Multifield` WHERE Field_ID=%d AND Message_ID=%s" % (PHOTO_FIELD_ID, mid))
        dbq("DELETE FROM `Message6` WHERE Message_ID=%s" % mid)


MARK = "ФототестФ2"


def teardown_module(module):
    cleanup(MARK + "%")


def test_valid_image_accepted():
    cleanup(MARK + "%")
    sid = login(SHADHAN)
    text = MARK + "-ok-51413"
    code, html = send_message(sid, text, "ok.png", png_bytes(), "image/png")
    assert code == 200, "отправка упала: %d" % code
    mid = find_message(text)
    assert mid, "сообщение не сохранилось"
    assert multifile_count(mid) == 1, "валидный PNG не сохранён во вложениях"
    # у валидной отправки нет маркера ошибки → JS выполнит редирект как раньше
    assert "js-upload-error" not in html, "у валидной отправки не должно быть маркера ошибки"


def test_non_image_rejected():
    # не-изображение (PDF-байты, даже с именем .png) → сообщение НЕ отправляется,
    # ответ содержит видимую причину (не молчаливый сбой)
    cleanup(MARK + "%")
    sid = login(SHADHAN)
    text = MARK + "-pdf-27182"
    pdf = b"%PDF-1.4\n%\xe2\xe3\xcf\xd3\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n"
    code, html = send_message(sid, text, "sneaky.png", pdf, "image/png")
    assert code == 200, "отправка упала: %d" % code
    assert not find_message(text), "не-изображение сохранилось как сообщение"
    assert "недопустимого типа" in html, "нет предупреждения о типе файла"
    assert "js-upload-error" in html, "ошибка не в маркере для показа пользователю"


def test_oversize_image_rejected():
    # изображение больше лимита (10 МБ) → сообщение НЕ отправляется, видна причина
    cleanup(MARK + "%")
    sid = login(SHADHAN)
    text = MARK + "-big-31415"
    big = png_bytes(pad=11 * 1024 * 1024)   # >10 МБ, валидный PNG-заголовок
    code, html = send_message(sid, text, "big.png", big, "image/png")
    assert code == 200, "отправка упала: %d" % code
    assert not find_message(text), "слишком большой файл сохранился как сообщение"
    assert "превышающим допустимый" in html, "нет предупреждения о размере"
    assert "js-upload-error" in html, "ошибка не в маркере для показа пользователю"
