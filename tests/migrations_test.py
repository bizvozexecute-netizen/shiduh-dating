# -*- coding: utf-8 -*-
"""
Интеграционные тесты системы миграций (ТЗ Э0.1).

Проверяют главное требование ТЗ: миграции применяются и откатываются чисто,
существующие данные не повреждаются. Гоняются против локального Docker.

⚠️ ЭТОТ НАБОР РАЗРУШИТЕЛЬНЫЙ И НЕ ПАРАЛЛЕЛИТСЯ С ДРУГИМИ.
setup_module делает `migrate down-all` — сносит ВСЮ схему миграций, а
teardown возвращает её обратно. Если в это же время идёт другой прогон
против той же базы, он посыплется на пустых таблицах и отсутствующих
настройках (проверено на себе: 11 «падений», которых на самом деле не
было). Запускать либо отдельно, либо в одной последовательной команде с
остальными наборами — но не двумя параллельными процессами.

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
NEW_TABLES = ["ai_chat_messages", "ai_prompts", "ai_field_writes", "invite_tokens",
              "mailings", "mailing_log", "mailing_optout", "assessment_cases",
              "assessment_answers", "match_feedback", "match_explanations",
              "moderation_notes"]
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
        # Реестр — источник правды для слоя LLM. Требование не «совпадает со
        # списком миграции 006», а шире: все поля 006 обязаны в нём быть, и
        # каждая запись должна ссылаться на реальную колонку. Так в реестр
        # можно дописывать поля, существовавшие до наших миграций (например,
        # Health_status), не ломая проверку.
        reg = sensitive_registry()
        missing = set(SENSITIVE_FIELDS) - set(reg)
        assert not missing, "поля миграции 006 выпали из реестра: " + repr(sorted(missing))
        for f in reg:
            assert col_exists("Message204", f), "реестр ссылается на несуществующую колонку: " + f

    def test_sensitive_registry_covers_health_fields(self):
        # Health_status существовал в проде ДО наших миграций и заполнен у
        # сотен анкет — это те же данные о здоровье (152-ФЗ). Выпадет из
        # реестра — уедет во внешнюю модель.
        reg = sensitive_registry()
        for f in ("Health_status", "EN_Health_status", "Search_health_status"):
            assert f in reg, "поле о здоровье не защищено реестром: " + f

    def test_answers_unique_per_case_version(self):
        # миграция 015: гонка двух путей ответа (опрос + диалог Э2.2-c) не
        # должна плодить дубль кейса внутри версии
        assert index_exists("assessment_answers", "user_case_version"),             "нет UNIQUE user_case_version на assessment_answers"

    def test_invite_tokens_unique_token(self):
        # миграция 007: токен-приглашение (Раздел XI). UNIQUE по token —
        # защита от коллизий и заготовка одноразовости
        assert table_exists("invite_tokens"), "нет таблицы invite_tokens"
        non_unique = dbq("SELECT Non_unique FROM information_schema.statistics "
                         "WHERE table_schema=DATABASE() AND table_name='invite_tokens' "
                         "AND column_name='token'")
        assert non_unique == "0", "нет UNIQUE-индекса по invite_tokens.token"

    def test_mailing_tables_created(self):
        # миграция 008: массовая рассылка (Раздел IV) — шапка, лог, отписка
        for t in ("mailings", "mailing_log", "mailing_optout"):
            assert table_exists(t), "нет таблицы " + t
        non_unique = dbq("SELECT Non_unique FROM information_schema.statistics "
                         "WHERE table_schema=DATABASE() AND table_name='mailing_optout' "
                         "AND column_name='token'")
        assert non_unique == "0", "нет UNIQUE-индекса по mailing_optout.token"

    def test_assessment_cases_seeded(self):
        # миграция 010: кейсы опроса (Э2.2-a) — таблица и стартовый набор
        assert table_exists("assessment_cases"), "нет таблицы assessment_cases"
        assert int(dbq("SELECT COUNT(*) FROM assessment_cases "
                       "WHERE dimension_group='love_language'")) >= 5
        assert int(dbq("SELECT COUNT(*) FROM assessment_cases "
                       "WHERE dimension_group='family_value'")) >= 7
        # все стартовые кейсы активны и с непустым текстом
        assert dbq("SELECT COUNT(*) FROM assessment_cases "
                   "WHERE active<>1 OR TRIM(scenario_text)=''") == "0"

    def test_assessment_cases_seed_not_duplicated_on_reup(self):
        # снимок берём ПОСЛЕ первого up: тогда результат не зависит от того,
        # что успели наделать соседние тесты (иначе up дозаполнил бы удалённое
        # и сравнение с внешним снимком развалилось)
        code, out = migrate("up")
        assert code == 0, "up упал:\n" + out
        before = dbq("SELECT COUNT(*) FROM assessment_cases")
        code, out = migrate("up")
        assert code == 0, "повторный up упал:\n" + out
        assert dbq("SELECT COUNT(*) FROM assessment_cases") == before, \
            "повторный up продублировал кейсы"

    def test_photo_field_format_limits(self):
        # миграция 009: вложения в сообщениях (Ф-2) — лимит 10 МБ и только image/*
        fmt = dbq("SELECT Format FROM Field WHERE Field_ID=648")
        assert fmt.startswith("10485760:image/*"), "формат поля фото без лимита: " + fmt[:40]

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

    def test_photo_field_format_restored(self):
        # 009 down: префикс лимита снят, формат вернулся к «без ограничений»
        fmt = dbq("SELECT Format FROM Field WHERE Field_ID=648")
        assert not fmt.startswith("10485760:image/*"), "лимит формата не снят: " + fmt[:40]

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
