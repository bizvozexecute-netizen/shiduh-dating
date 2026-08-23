# -*- coding: utf-8 -*-
"""
Тесты эмбеддеров RAG-ретривера (ТЗ Э2.1-c).

Эмбеддер — единственная точка подмены: сейчас работает оффлайн-заглушка
HashingEmbedder (bag-of-words по стабильному хешу, без ключа), позже её
заменит OpenRouterEmbedder (ждёт ключ, Э2.1-b). Контракт общий: embed()
возвращает numpy-матрицу (n, dim), L2-нормированную, детерминированную.
"""
import numpy as np
import pytest

from rag.service.embedder import HashingEmbedder, OpenRouterEmbedder


@pytest.fixture
def emb():
    return HashingEmbedder(dim=256)


class TestShape:
    def test_returns_matrix_n_by_dim(self, emb):
        v = emb.embed(["мир в доме", "любовь и милосердие", "третий текст"])
        assert v.shape == (3, 256)

    def test_dim_property(self, emb):
        assert emb.dim == 256

    def test_has_name(self, emb):
        assert isinstance(emb.name, str) and emb.name


class TestNormalization:
    def test_rows_are_unit_length(self, emb):
        v = emb.embed(["шалом байт", "совсем другой текст про семью"])
        norms = np.linalg.norm(v, axis=1)
        assert np.allclose(norms, 1.0, atol=1e-6)

    def test_empty_text_is_zero_vector(self, emb):
        v = emb.embed([""])
        assert np.allclose(v[0], 0.0), "пустой текст должен давать нулевой вектор"


class TestDeterminism:
    def test_same_text_same_vector(self):
        a = HashingEmbedder(dim=256).embed(["мир в доме строится на любви"])
        b = HashingEmbedder(dim=256).embed(["мир в доме строится на любви"])
        assert np.allclose(a, b), "эмбеддинг должен быть детерминирован между процессами"

    def test_different_texts_differ(self, emb):
        v = emb.embed(["мир в доме", "война и хаос снаружи"])
        assert not np.allclose(v[0], v[1])


class TestSemanticsLexical:
    def test_word_overlap_scores_higher(self, emb):
        # косинус (для нормированных = скалярное произведение): пересекающиеся
        # по словам тексты ближе, чем непересекающиеся
        v = emb.embed([
            "мир и любовь в еврейской семье",
            "любовь и мир в семье это главное",
            "налоговая отчётность за квартал",
        ])
        sim_related = float(v[0] @ v[1])
        sim_unrelated = float(v[0] @ v[2])
        assert sim_related > sim_unrelated


class _FakeResp:
    def __init__(self, data):
        self._data = data

    def raise_for_status(self):
        pass

    def json(self):
        return {"data": self._data}


class TestOpenRouterEmbedder:
    """
    Реальный путь (Э2.1-b) с подменённым httpx: проверяем батчинг больших
    входов и восстановление порядка по полю index (провайдер-прокси не
    гарантирует порядок ответа).
    """

    def _install_fake(self, monkeypatch, calls, dim=512, reverse=False):
        import httpx

        def fake_post(url, headers=None, json=None, timeout=None):
            inputs = json["input"]
            calls.append(len(inputs))
            # one-hot по номеру из текста "t{n}" — переживает L2-нормировку,
            # так что выравнивание вход↔выход проверяемо через argmax
            data = []
            for pos, text in enumerate(inputs):
                n = int(text[1:])
                vec = [0.0] * dim
                vec[n % dim] = 1.0
                data.append({"index": pos, "embedding": vec})
            if reverse:
                data = list(reversed(data))   # индекс верный, порядок в списке — нет
            return _FakeResp(data)

        monkeypatch.setattr(httpx, "post", fake_post)

    def test_batches_large_input(self, monkeypatch):
        calls = []
        self._install_fake(monkeypatch, calls, dim=512)
        emb = OpenRouterEmbedder(api_key="sk-x", model="m",
                                 base_url="http://x/v1", dim=512, max_items=128)
        texts = ["t%d" % i for i in range(300)]
        v = emb.embed(texts)
        assert v.shape == (300, 512)
        assert len(calls) >= 3, "большой вход должен уйти несколькими батчами: %s" % calls
        assert max(calls) <= 128, "батч превысил лимит элементов: %s" % calls
        # выравнивание сохранено
        for i in range(300):
            assert int(np.argmax(v[i])) == i % 512

    def test_reorders_by_index(self, monkeypatch):
        calls = []
        self._install_fake(monkeypatch, calls, dim=512, reverse=True)
        emb = OpenRouterEmbedder(api_key="sk-x", model="m",
                                 base_url="http://x/v1", dim=512, max_items=128)
        texts = ["t%d" % i for i in range(10)]
        v = emb.embed(texts)
        # несмотря на перевёрнутый список в ответе, index восстанавливает порядок
        for i in range(10):
            assert int(np.argmax(v[i])) == i

    def test_result_is_normalized(self, monkeypatch):
        calls = []
        self._install_fake(monkeypatch, calls, dim=512)
        emb = OpenRouterEmbedder(api_key="sk-x", model="m",
                                 base_url="http://x/v1", dim=512)
        v = emb.embed(["t1", "t2", "t3"])
        assert np.allclose(np.linalg.norm(v, axis=1), 1.0, atol=1e-6)

    def test_empty_input(self, monkeypatch):
        calls = []
        self._install_fake(monkeypatch, calls, dim=512)
        emb = OpenRouterEmbedder(api_key="sk-x", model="m",
                                 base_url="http://x/v1", dim=512)
        v = emb.embed([])
        assert v.shape == (0, 512)
        assert calls == [], "пустой вход не должен слать запросов"

    def test_requires_api_key(self):
        with pytest.raises(ValueError):
            OpenRouterEmbedder(api_key="", model="m", base_url="http://x/v1", dim=512)
