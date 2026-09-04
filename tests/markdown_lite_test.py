# -*- coding: utf-8 -*-
"""
B4 этапа «До идеала»: безопасная разметка ответов консультанта.

Модель отвечает markdown-ом, а показывался он сырым: человек видел «**важно**»
и «- пункт» как есть. Готовый разборщик сюда не годится — он умеет пропускать
HTML, а текст модели это чужой ввод. Поэтому своя узкая грамматика, устроенная
так, что вставить разметку невозможно: текст экранируется ЦЕЛИКОМ, и только
потом на безопасной строке расставляются теги.

Грамматика продублирована в PHP (серверная история) и в JS (живые сообщения).
Две реализации обязаны совпадать до символа — иначе после перезагрузки страницы
человек увидит другой текст, чем видел минуту назад. Совпадение держится
общими фикстурами: каждый случай прогоняется через обе.

    python -m pytest tests/markdown_lite_test.py -q
"""
import io
import json
import os
import shutil
import subprocess
import sys

import pytest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from fake_llm import ROOT, web  # noqa: E402

FIXTURES = os.path.join(ROOT, "tests", "fixtures", "markdown_lite_cases.json")
PHP_LIB = "/var/www/html/api/markdown_lite.php"
JS_LIB = os.path.join(ROOT, "shiduh.org", "js", "chat.js")
NODE = shutil.which("node") or r"C:\Program Files\nodejs\node.exe"

CASES = json.load(io.open(FIXTURES, encoding="utf-8"))
IDS = [c["why"] for c in CASES]


def php_render(text):
    """Рендер через PHP внутри контейнера (base64, чтобы кавычки и переносы
    доехали без искажений)."""
    import base64
    payload = base64.b64encode(text.encode("utf-8")).decode("ascii")
    code = ("require '%s'; echo markdown_lite_html(base64_decode('%s'));" % (PHP_LIB, payload))
    r = web("php", "-r", code)
    assert r.returncode == 0, "php упал: " + (r.stderr or "")[:300]
    return r.stdout


def js_render_all(texts):
    """Все случаи одним запуском node: старт процесса дороже самой работы."""
    import base64
    harness = os.path.join(ROOT, "tests", "fixtures", "_mdlite_harness.cjs")
    # .cjs: выше по дереву есть package.json с "type": "module"
    io.open(harness, "w", encoding="utf-8", newline="\n").write(
        "const fs = require('fs');\n"
        "const src = fs.readFileSync(process.argv[2], 'utf8');\n"
        "const m = src.match(/\\/\\* MDLITE-START \\*\\/([\\s\\S]*?)\\/\\* MDLITE-END \\*\\//);\n"
        "if (!m) { console.error('нет блока MDLITE в chat.js'); process.exit(2); }\n"
        "const mdlite = new Function(m[1] + '; return mdlite;')();\n"
        "const inputs = JSON.parse(Buffer.from(process.argv[3], 'base64').toString('utf8'));\n"
        "console.log(JSON.stringify(inputs.map(t => mdlite.toHtml(t))));\n"
    )
    payload = base64.b64encode(json.dumps(texts, ensure_ascii=False).encode("utf-8")).decode("ascii")
    r = subprocess.run([NODE, harness, JS_LIB, payload], capture_output=True, text=True,
                       encoding="utf-8", timeout=60)
    assert r.returncode == 0, "node упал: " + (r.stderr or "")[:400]
    return json.loads(r.stdout.strip().splitlines()[-1])


_JS_CACHE = {}


def js_render(text):
    if not _JS_CACHE:
        rendered = js_render_all([c["in"] for c in CASES])
        for case, html in zip(CASES, rendered):
            _JS_CACHE[case["in"]] = html
    return _JS_CACHE[text]


# ------------------------------------------------------------------- PHP ---

@pytest.mark.parametrize("case", CASES, ids=IDS)
def test_php_matches_fixture(case):
    assert php_render(case["in"]) == case["html"], case["why"]


# -------------------------------------------------------------------- JS ---

@pytest.mark.skipif(not os.path.exists(NODE), reason="нет node для прогона браузерного кода")
@pytest.mark.parametrize("case", CASES, ids=IDS)
def test_js_matches_fixture(case):
    assert js_render(case["in"]) == case["html"], case["why"]


# ------------------------------------------------------------ инварианты ---

def test_no_raw_html_leaks_through():
    """Ни один случай с угловыми скобками во вводе не должен дать сырой тег
    вне разрешённого набора."""
    allowed = ("<p>", "</p>", "<br>", "<strong>", "</strong>", "<em>", "</em>",
               "<code>", "</code>", "<ul>", "</ul>", "<ol>", "</ol>", "<li>", "</li>", "<a ", "</a>")
    for case in CASES:
        if "<" not in case["in"]:
            continue
        html = php_render(case["in"])
        rest = html
        for tag in allowed:
            rest = rest.replace(tag, "")
        assert "<" not in rest, "сырой тег в выводе для %r: %r" % (case["why"], html)


def test_links_only_http_schemes():
    for case in CASES:
        html = php_render(case["in"])
        for chunk in html.split('href="')[1:]:
            url = chunk.split('"')[0]
            assert url.startswith(("http://", "https://")), "недопустимая схема: " + url


@pytest.mark.skipif(not os.path.exists(NODE), reason="нет node")
def test_php_and_js_agree_on_random_input():
    """Фаззинг: случайные строки из «опасного» алфавита. Две реализации должны
    расходиться только вместе с фикстурами, а не тихо."""
    import random
    random.seed(7731)
    alphabet = list("*_`[]()<>\"'&\n- 1.абв") + ["https://a.b", "**", "__"]
    texts = ["".join(random.choice(alphabet) for _ in range(random.randint(1, 40)))
             for _ in range(60)]
    js = js_render_all(texts)
    for text, js_html in zip(texts, js):
        assert php_render(text) == js_html, "расхождение на %r" % text
