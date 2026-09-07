# -*- coding: utf-8 -*-
"""
Восстановление строк dev-БД из анонимизированного дампа.

Зачем: наборы подбора (match_test.py и др.) правят реальные анкеты и
возвращают их в teardown; прерванный прогон (упал Docker, убита сессия)
оставляет в базе сентинелы вроде City='Матчбург-Тест'. Скрипт берёт кортеж
строки из INSERT дампа и делает UPDATE по колонкам дампа — строка
возвращается целиком, а колонки, добавленные нашими миграциями в конец,
не трогаются.

    python tools/restore_rows_from_dump.py Message204 2159 2161 2163
    python tools/restore_rows_from_dump.py Message204 --find   # найти сентинелы

Дамп: dump-out/dump.anonymized.sql (не в git). Один INSERT на таблицу с
кортежами через запятую — читаем потоково, разбираем кавычки сами.

Только для таблиц Message*: строки User восстанавливать нельзя — в дампе
пароли MD5, а после C6 в базе bcrypt (затёрлись бы).
"""
import io
import os
import re
import subprocess
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DUMP = os.path.join(ROOT, "dump-out", "dump.anonymized.sql")
DOCKER = r"C:\Program Files\Docker\Docker\resources\bin\docker.exe"

SENTINEL_SQL = ("SELECT Message_ID, Name, City FROM `Message204` WHERE City LIKE '%Тест%' "
                "OR Name LIKE '%Тест%' OR Name LIKE '%Обезлич%' OR Name LIKE '%Сентинел%' "
                "OR Name LIKE '%Идентификатор%' OR Email LIKE '%.local' AND Email NOT LIKE 'u%@example.local'")


def mysql(sql):
    # SQL через stdin, а не -e: командная строка Windows ограничена ~32 К
    # символов, а кортеж с длинными текстовыми полями в неё не влезет
    r = subprocess.run(
        [DOCKER, "compose", "exec", "-T", "db", "mysql", "-uroot", "-proot_local_dev",
         "-N", "-B", "--default-character-set=utf8", "shiduh_org"],
        input=sql, cwd=ROOT, capture_output=True, text=True, encoding="utf-8", errors="replace", timeout=300)
    if r.returncode != 0:
        raise RuntimeError(r.stderr)
    return (r.stdout or "").strip()


def dump_columns(table):
    cols = []
    with io.open(DUMP, encoding="utf-8", errors="replace") as f:
        inside = False
        for line in f:
            if line.startswith("CREATE TABLE `%s`" % table):
                inside = True
                continue
            if inside:
                m = re.match(r"\s*`([^`]+)`\s", line)
                if m:
                    cols.append(m.group(1))
                elif line.startswith(")"):
                    break
    if not cols:
        raise SystemExit("CREATE TABLE %s не найден в дампе" % table)
    return cols


def iter_tuples(table):
    """Кортежи INSERT для таблицы, как текст SQL: (v1,v2,...)."""
    prefix = "INSERT INTO `%s` VALUES " % table
    with io.open(DUMP, encoding="utf-8", errors="replace") as f:
        for line in f:
            if not line.startswith(prefix):
                continue
            s = line[len(prefix):]
            i, n = 0, len(s)
            while i < n:
                if s[i] != "(":
                    i += 1
                    continue
                start = i
                depth, quote = 0, None
                while i < n:
                    ch = s[i]
                    if quote:
                        if ch == "\\":
                            i += 2
                            continue
                        if ch == quote:
                            quote = None
                    elif ch in ("'", '"'):
                        quote = ch
                    elif ch == "(":
                        depth += 1
                    elif ch == ")":
                        depth -= 1
                        if depth == 0:
                            yield s[start:i + 1]
                            break
                    i += 1
                i += 1


def split_values(tup):
    """(a,'b,c',NULL) → ['a', "'b,c'", 'NULL'] — литералы как в SQL."""
    s = tup[1:-1]
    out, cur, quote, i = [], [], None, 0
    while i < len(s):
        ch = s[i]
        if quote:
            cur.append(ch)
            if ch == "\\":
                cur.append(s[i + 1])
                i += 2
                continue
            if ch == quote:
                quote = None
        elif ch in ("'", '"'):
            quote = ch
            cur.append(ch)
        elif ch == ",":
            out.append("".join(cur))
            cur = []
        else:
            cur.append(ch)
        i += 1
    out.append("".join(cur))
    return out


def restore(table, ids):
    cols = dump_columns(table)
    key = cols[0]
    want = {str(int(i)) for i in ids}
    done = set()
    for tup in iter_tuples(table):
        vals = split_values(tup)
        if vals[0] not in want:
            continue
        assert len(vals) == len(cols), "колонок в кортеже %d, в CREATE %d" % (len(vals), len(cols))
        sets = ", ".join("`%s`=%s" % (c, v) for c, v in zip(cols[1:], vals[1:]))
        mysql("UPDATE `%s` SET %s WHERE `%s`=%s" % (table, sets, key, vals[0]))
        done.add(vals[0])
        print("восстановлена строка", table, vals[0])
        if done == want:
            break
    missing = want - done
    if missing:
        print("НЕ найдены в дампе:", ", ".join(sorted(missing)))


if __name__ == "__main__":
    if len(sys.argv) < 3:
        raise SystemExit(__doc__)
    table = sys.argv[1]
    if not table.startswith("Message"):
        raise SystemExit("только таблицы Message*: у User в дампе пароли MD5, в базе bcrypt")
    if sys.argv[2] == "--find":
        print(mysql(SENTINEL_SQL.replace("`Message204`", "`%s`" % table)) or "сентинелов нет")
    else:
        restore(table, sys.argv[2:])
