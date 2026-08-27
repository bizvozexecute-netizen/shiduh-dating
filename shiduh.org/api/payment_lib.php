<?php
/*
 * Интерфейс платёжного шлюза (ТЗ Э4-3).
 *
 * Реальный шлюз ВНЕ ТЗ: здесь контракт и заглушки ЮKassa/Stripe, чтобы
 * подключение живого провайдера свелось к одному классу, не трогая
 * пейволл и админку. Все методы возвращают единый конверт:
 *   array('ok' => bool, ...данные..., 'error' => string|null)
 */

interface PaymentGateway {
    /* Создать платёж; в ответе ok/payment_id/confirmation_url. */
    public function create_payment($user_id, $amount, $currency, $description);

    /* Обработать вебхук провайдера; в ответе ok/payment_id/status. */
    public function handle_webhook($payload);

    /* Статус платежа: pending | succeeded | canceled. */
    public function check_status($payment_id);
}

/*
 * Общая заглушка: честно отвечает «шлюз не настроен» вместо имитации
 * успеха — пейволл не должен открываться от фиктивного платежа.
 */
abstract class PaymentGatewayStub implements PaymentGateway {
    protected $name = 'шлюз';

    protected function stub() {
        return array('ok' => false, 'error' => 'Платёжный ' . $this->name
            . ' не настроен. Обратитесь к администратору сайта.');
    }

    public function create_payment($user_id, $amount, $currency, $description) {
        return $this->stub();
    }

    public function handle_webhook($payload) {
        return $this->stub();
    }

    public function check_status($payment_id) {
        return $this->stub();
    }
}

class YookassaGateway extends PaymentGatewayStub {
    protected $name = 'шлюз ЮKassa';
}

class StripeGateway extends PaymentGatewayStub {
    protected $name = 'шлюз Stripe';
}

/*
 * Фабрика. Без аргумента имя берётся из настроек (Settings ai.payment_gateway),
 * чтобы живой провайдер включался конфигурацией, а не правкой кода;
 * неизвестное имя — заглушка ЮKassa (РФ).
 */
function payment_gateway($name = null) {
    if ($name === null) {
        $name = nc_core::get_object()->get_settings('payment_gateway', 'ai', false, 0);
    }
    switch ((string)$name) {
        case 'stripe':
            return new StripeGateway();
        case 'yookassa':
        default:
            return new YookassaGateway();
    }
}
