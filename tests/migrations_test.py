# -*- coding: utf-8 -*-
"""
Интеграционные тесты системы миграций (ТЗ Э0.1).

Проверяют главное требование ТЗ: миграции применяются и откатываются чисто,
существующие данные не повреждаются. Гоняются против локального Docker.

Запуск (окружение поднято):
    python -m pytest tests/migrations_test.py -q
"""
import os
import subprocess
import sys

DOCKER = r"C:\Program Files\Docker\Docker\resources\bin\docker.exe"
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
MIGRATE = "/var/www/html/migrations/migrate.php"


def _run(args, timeout=180):
    return subprocess.run(
        [DOCKER, "compose", "exec", "-T"] + args,
        cwd=ROOT, capture_output=True, text=True, timeout=timeout,
        encoding="utf-8", errors="replace",
    )


def migrate(cmd):
    """Запуск раннера миграций в web-контейнере. Возвращает (код, stdout+stderr)."""
    r = _run(["web", "php", MIGRATE, cmd])
    return r.returncode, (r.stdout or "") + (r.stderr or "")


def dbq(sql):
    """SQL-запрос к БД, вывод без заголовка (-N -B)."""
    r = _run(["db", "mysql", "-uroot", "-proot_local_dev", "-N", "-B",
              "--default-character-set=utf8", "shiduh_org", "-e", sql])
    return (r.stdout or "").strip()


def col_exists(table, column):
    return dbq("SHOW COLUMNS FROM `%s` LIKE '%s'" % (table, column)) != ""


def table_exists(table):
    return dbq("SHOW TABLES LIKE '%s'" % table) != ""


def index_exists(table, index):
    return dbq("SHOW INDEX FROM `%s` WHERE Key_name='%s'" % (table, index)) != ""


def count(table):
    return int(dbq("SELECT COUNT(*) FROM `%s`" % table) or 0)


# Поля, которые миграции добавляют к анкете Message204
PROFILE_FIELDS = [
    "About_self", "Kashrut_level", "Shabbat_level", "Family_values",
    "AI_summary", "AI_summary_meta", "Psychological_profile",
    "Profile_embedding", "Embedding_meta",
]
NEW_TABLES = ["ai_chat_messages", "ai_prompts", "ai_field_writes", "invite_tokens"]
NEW_DICTS = ["Classificator_Kashrut_level", "Classificator_Shabbat_level",
             "Classificator_Value_dimension"]
DICT_NAMES = ["Kashrut_level", "Shabbat_level", "Value_dimension"]

# Миграция 006 (встреча с шадханами 2026-08-18): новые необязательные поля.
MEETING_FIELDS = [
    "Sport_attitude", "Housing_after_marriage", "Languages_spoken",
    "Diaspora_openness", "Observance_growth", "Rabbinate_registration",
    "Introvert_extrovert",
]
# Чувствительные поля (152-ФЗ): хранятся, но не уходят в зарубежный LLM.
SENSITIVE_FIELDS = ["Income_property", "Health_conditions", "Medications"]
MEETING006_FIELDS = MEETING_FIELDS + SENSITIVE_FIELDS
SENSITIVE_CONFIG = "/var/www/html/api/ai_sensitive_fields.php"


def sensitive_registry():
    """Реестр чувствительных полей из PHP-конфига (источник для слоя LLM)."""
    r = _run(["web", "php", "-r",
              "foreach ((array)(@include '%s') as $f) { echo $f, PHP_EOL; }" % SENSITIVE_CONFIG])
    return [ln.strip() for ln in (r.stdout or "").splitlines() if ln.strip()]

BASELINE_COLS = 131          # колонок в Message204 до миграций (AUDIT.md §5.1)
BASELINE_ROWS = 1189         # строк в Message204
BASELINE_NAME_FILLED = 1189  # Name заполнено у всех


def setup_module(module):
    # чистое состояние: откатываем всё, что могло остаться от прошлых прогонов
    migrate("down-all")


def teardown_module(module):
    # Возвращаем схему в штатное «всё применено»: dev-среда и остальные наборы
    # тестов рассчитывают на применённые миграции (поля AI_summary и др.).
    migrate("down-all")
    migrate("up")


def message204_cols():
    return int(dbq("SELECT COUNT(*) FROM information_schema.columns "
                   "WHERE table_schema=DATABASE() AND table_name='Message204'") or 0)


class TestUp:
    def test_up_applies_cleanly(self):
        code, out = migrate("up")
        assert code == 0, "up завершился с ошибкой:\n" + out

    def test_new_tables_created(self):
        for t in NEW_TABLES:
            assert table_exists(t), "нет таблицы " + t

    def test_dictionaries_created_and_registered(self):
        for t in NEW_DICTS:
            assert table_exists(t), "нет справочника " + t
            assert count(t) > 0, "справочник пуст: " + t
        # зарегистрированы в реестре Classificator
        for name in DICT_NAMES:
            assert dbq("SELECT COUNT(*) FROM Classificator WHERE Table_Name='%s'" % name) == "1"

    def test_dict_values_count_exact(self):
        # per-item guard должен дать ровно ожидаемое число значений
        assert count("Classificator_Kashrut_level") == 4
        assert count("Classificator_Shabbat_level") == 4
        assert count("Classificator_Value_dimension") == 7

    def test_profile_columns_added(self):
        for f in PROFILE_FIELDS:
            assert col_exists("Message204", f), "нет колонки Message204." + f

    def test_profile_fields_registered_in_Field(self):
        # NetCat должен знать о полях — иначе не покажет в формах/шаблонах
        for f in PROFILE_FIELDS:
            n = dbq("SELECT COUNT(*) FROM Field WHERE Class_ID=204 AND Field_Name='%s'" % f)
            assert n == "1", "поле не зарегистрировано в Field: " + f

    def test_meeting_fields_added(self):
        # миграция 006: новые необязательные поля из встречи с шадханами
        for f in MEETING006_FIELDS:
            assert col_exists("Message204", f), "нет колонки Message204." + f

    def test_meeting_fields_registered_in_Field(self):
        for f in MEETING006_FIELDS:
            n = dbq("SELECT COUNT(*) FROM Field WHERE Class_ID=204 AND Field_Name='%s'" % f)
            assert n == "1", "поле не зарегистрировано в Field: " + f

    def test_meeting_fields_empty_not_fabricated(self):
        # новые поля не должны быть заполнены — иначе портят метрику заполненности
        for f in MEETING006_FIELDS:
            filled = dbq("SELECT COUNT(*) FROM Message204 WHERE `%s` IS NOT NULL AND `%s`<>''" % (f, f))
            assert filled == "0", "новое поле неожиданно заполнено: " + f

    def test_sensitive_registry_matches_columns(self):
        # реестр чувствительных полей существует и ссылается только на реальные
        # колонки Message204 (иначе слой LLM не сможет их вырезать)
        reg = sensitive_registry()
        assert set(reg) == set(SENSITIVE_FIELDS), \
            "реестр чувствительных полей разошёлся: " + repr(reg)
        for f in reg:
            assert col_exists("Message204", f), "реестр ссылается на несуществующую колонку: " + f

    def test_invite_tokens_unique_token(self):
        # миграция 007: токен-приглашение (Раздел XI). UNIQUE по token —
        # защита от коллизий и заготовка одноразовости
        assert table_exists("invite_tokens"), "нет таблицы invite_tokens"
        non_unique = dbq("SELECT Non_unique FROM information_schema.statistics "
                         "WHERE table_schema=DATABASE() AND table_name='invite_tokens' "
                         "AND column_name='token'")
        assert non_unique == "0", "нет UNIQUE-индекса по invite_tokens.token"

    def test_composite_index_for_matching(self):
        assert index_exists("Message204", "idx_match"), "нет составного индекса подбора"

    def test_ai_consultant_section_created(self):
        # миграция 005: раздел /my/ai-consultant/ → компонент 323
        assert dbq("SELECT Subdivision_ID FROM Subdivision WHERE EnglishName='ai-consultant'") == "89"
        assert dbq("SELECT COUNT(*) FROM Sub_Class WHERE Sub_Class_ID=99 AND Class_ID=323") == "1"
        assert dbq("SELECT File_Path FROM Class WHERE Class_ID=323") == "/323/"
        assert table_exists("Message323")

    def test_data_not_damaged(self):
        assert count("Message204") == BASELINE_ROWS
        assert int(dbq("SELECT COUNT(*) FROM Message204 WHERE Name<>''")) == BASELINE_NAME_FILLED

    def test_new_field_is_empty_not_fabricated(self):
        # новое поле не должно быть заполнено — иначе портит метрику заполненности
        assert dbq("SELECT COUNT(*) FROM Message204 WHERE About_self IS NOT NULL AND About_self<>''") == "0"

    def test_up_is_idempotent(self):
        code, out = migrate("up")
        assert code == 0, "повторный up упал:\n" + out
        # число полей не удвоилось
        n = dbq("SELECT COUNT(*) FROM Field WHERE Class_ID=204 AND Field_Name='About_self'")
        assert n == "1", "повторный up продублировал поле"


class TestStatus:
    def test_status_lists_all_applied(self):
        code, out = migrate("status")
        assert code == 0
        assert "001" in out and "002" in out
        # после up все помечены применёнными
        assert out.lower().count("применена") >= 4 or out.count("[x]") >= 4


class TestDown:
    def test_down_all_reverts_cleanly(self):
        code, out = migrate("down-all")
        assert code == 0, "down-all упал:\n" + out

    def test_new_tables_removed(self):
        for t in NEW_TABLES + NEW_DICTS:
            assert not table_exists(t), "таблица не удалена: " + t
        # реестр Classificator очищен от всех трёх справочников
        for name in DICT_NAMES:
            assert dbq("SELECT COUNT(*) FROM Classificator WHERE Table_Name='%s'" % name) == "0", \
                "остался в реестре: " + name

    def test_profile_columns_removed(self):
        for f in PROFILE_FIELDS:
            assert not col_exists("Message204", f), "колонка не удалена: " + f
            assert dbq("SELECT COUNT(*) FROM Field WHERE Class_ID=204 AND Field_Name='%s'" % f) == "0"

    def test_meeting_fields_removed(self):
        for f in MEETING006_FIELDS:
            assert not col_exists("Message204", f), "колонка 006 не удалена: " + f
            assert dbq("SELECT COUNT(*) FROM Field WHERE Class_ID=204 AND Field_Name='%s'" % f) == "0"

    def test_schema_back_to_baseline(self):
        assert message204_cols() == BASELINE_COLS, "число колонок Message204 не вернулось к 131"

    def test_ai_consultant_section_removed(self):
        # миграция 005 откатывается чисто: раздел, привязка, компонент, таблица
        assert dbq("SELECT COUNT(*) FROM Subdivision WHERE EnglishName='ai-consultant'") == "0"
        assert dbq("SELECT COUNT(*) FROM Sub_Class WHERE Sub_Class_ID=99") == "0"
        assert dbq("SELECT COUNT(*) FROM Class WHERE Class_ID=323") == "0"
        assert not table_exists("Message323")

    def test_data_intact_after_rollback(self):
        assert count("Message204") == BASELINE_ROWS
        assert int(dbq("SELECT COUNT(*) FROM Message204 WHERE Name<>''")) == BASELINE_NAME_FILLED
