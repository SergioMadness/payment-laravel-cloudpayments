<?php namespace professionalweb\payment\drivers\cloudpayments;

use Illuminate\Support\Arr;
use Illuminate\Http\Response;
use professionalweb\payment\contracts\Form;
use professionalweb\payment\models\Schedule;
use professionalweb\payment\contracts\Receipt;
use professionalweb\payment\contracts\PayService;
use professionalweb\payment\contracts\PayProtocol;
use professionalweb\payment\models\PayServiceOption;
use professionalweb\payment\interfaces\CloudPaymentsService;
use professionalweb\payment\interfaces\CloudPaymentProtocol;
use professionalweb\payment\contracts\recurring\RecurringSchedule;
use professionalweb\payment\contracts\recurring\RecurringPaymentSchedule;

/**
 * CloudPayments implementation
 * @package professionalweb\payment\drivers\cloudpayments
 */
class CloudPaymentsDriver implements PayService, CloudPaymentsService, RecurringPaymentSchedule
{

    //<editor-fold desc="Fields">
    /**
     * @var PayProtocol
     */
    private $transport;

    /** @var CloudPaymentProtocol */
    private $cloudPaymentsProtocol;

    /**
     * Notification info
     *
     * @var array
     */
    protected $response;

    /** @var bool */
    private $useWidget;

    //</editor-fold>

    public function __construct(bool $useWidget = false)
    {
        $this->useWidget($useWidget);
    }

    /**
     * Get name of payment service
     *
     * @return string
     */
    public function getName(): string
    {
        return self::PAYMENT_CLOUDPAYMENTS;
    }

    /**
     * Pay
     *
     * @param mixed   $orderId
     * @param mixed   $paymentId
     * @param float   $amount
     * @param string  $currency
     * @param string  $paymentType
     * @param string  $successReturnUrl
     * @param string  $failReturnUrl
     * @param string  $description
     * @param array   $extraParams
     * @param Receipt $receipt
     *
     * @return string
     * @throws \Exception
     */
    public function getPaymentLink($orderId,
                                   $paymentId,
                                   float $amount,
                                   string $currency = self::CURRENCY_RUR,
                                   string $paymentType = self::PAYMENT_TYPE_CARD,
                                   string $successReturnUrl = '',
                                   string $failReturnUrl = '',
                                   string $description = '',
                                   array $extraParams = [],
                                   Receipt $receipt = null): string
    {
        if ($this->getUseWidget()) {
            return $successReturnUrl;
        }

        if (!isset($extraParams['checkout'], $extraParams['cardholder_name'])) {
            throw new \Exception('checkout and cardholder_name params are required');
        }

        $request = [
            'Email'                => $extraParams['email'] ?? null,
            'Amount'               => $amount,
            'Currency'             => $currency,
            'InvoiceId'            => $orderId,
            'Description'          => $description,
            'AccountId'            => $this->getAccountId(),
            'Name'                 => $extraParams['cardholder_name'],
            'CardCryptogramPacket' => $extraParams['checkout'],
            'IpAddress'            => $extraParams['ip'] ?? ($_SERVER['HTTP_CLIENT_IP'] ?? ''),
            'JsonData'             => array_merge($extraParams, ['PaymentId' => $paymentId]),
        ];

        $paymentUrl = $this->getTransport()->getPaymentUrl($request);

        return $paymentUrl;
    }

    /**
     * Payment system need form
     * You can not get url for redirect
     *
     * @return bool
     */
    public function needForm(): bool
    {
        return false;
    }

    /**
     * Generate payment form
     *
     * @param mixed   $orderId
     * @param mixed   $paymentId
     * @param float   $amount
     * @param string  $currency
     * @param string  $paymentType
     * @param string  $successReturnUrl
     * @param string  $failReturnUrl
     * @param string  $description
     * @param array   $extraParams
     * @param Receipt $receipt
     *
     * @return Form
     */
    public function getPaymentForm($orderId,
                                   $paymentId,
                                   float $amount,
                                   string $currency = self::CURRENCY_RUR,
                                   string $paymentType = self::PAYMENT_TYPE_CARD,
                                   string $successReturnUrl = '',
                                   string $failReturnUrl = '',
                                   string $description = '',
                                   array $extraParams = [],
                                   Receipt $receipt = null): Form
    {
        return new Form();
    }

    /**
     * Validate request
     *
     * @param array $data
     *
     * @return bool
     */
    public function validate(array $data): bool
    {
        return true;
    }

    /**
     * Parse notification
     *
     * @param array $data
     *
     * @return $this
     */
    public function setResponse(array $data): PayService
    {
        $this->response = $data;

        return $this;
    }

    /**
     * Get response param by name
     *
     * @param string $name
     * @param string $default
     *
     * @return mixed|string
     */
    public function getResponseParam(string $name, $default = '')
    {
        return Arr::get($this->response, $name, $default);
    }

    /**
     * Get order ID
     *
     * @return string
     */
    public function getOrderId(): string
    {
        return $this->getResponseParam('Model.InvoiceId', $this->getResponseParam('InvoiceId'));
    }

    /**
     * Get payment id
     *
     * @return string
     */
    public function getPaymentId(): string
    {
        $data = $this->getResponseParam('Model.JsonData', $this->getResponseParam('Data', []));
        if (is_string($data)) {
            $data = json_decode($data);
        }

        return $data['PaymentId'] ?? '';
    }

    /**
     * Get operation status
     *
     * @return string
     */
    public function getStatus(): string
    {
        return $this->getResponseParam('Model.Status', $this->getResponseParam('Status'));
    }

    /**
     * Is payment succeed
     *
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->getResponseParam('Success', true);
    }

    /**
     * Get transaction ID
     *
     * @return string
     */
    public function getTransactionId(): string
    {
        return $this->getResponseParam('Model.TransactionId', $this->getResponseParam('TransactionId'));
    }

    /**
     * Get transaction amount
     *
     * @return float
     */
    public function getAmount(): float
    {
        return $this->getResponseParam('Model.Amount', $this->getResponseParam('Amount'));
    }

    /**
     * Get error code
     *
     * @return string
     */
    public function getErrorCode(): string
    {
        return $this->getResponseParam('Model.Message', '');
    }

    /**
     * Get payment provider
     *
     * @return string
     */
    public function getProvider(): string
    {
        return self::PAYMENT_TYPE_CARD;
    }

    /**
     * Get PAN
     *
     * @return string
     */
    public function getPan(): string
    {
        return $this->getResponseParam('Model.CardFirstSix', $this->getResponseParam('CardFirstSix')) . '******' . $this->getResponseParam('Model.CardLastFour', $this->getResponseParam('CardLastFour'));
    }

    /**
     * Get payment datetime
     *
     * @return string
     */
    public function getDateTime(): string
    {
        return $this->getResponseParam('Model.CreatedDateIso', $this->getResponseParam('CreatedDateIso'));
    }

    /**
     * Set transport/protocol wrapper
     *
     * @param PayProtocol $protocol
     *
     * @return $this
     */
    public function setTransport(PayProtocol $protocol): PayService
    {
        $this->transport = $protocol;

        return $this;
    }

    /**
     * @param CloudPaymentProtocol $protocol
     *
     * @return CloudPaymentsDriver
     */
    public function setCloudPaymentsProtocol(CloudPaymentProtocol $protocol): self
    {
        $this->cloudPaymentsProtocol = $protocol;

        return $this->setTransport($protocol);
    }

    /**
     * @return CloudPaymentProtocol
     */
    public function getCloudPaymentsProtocol(): CloudPaymentProtocol
    {
        return $this->cloudPaymentsProtocol;
    }

    /**
     * Get transport
     *
     * @return PayProtocol
     */
    public function getTransport(): PayProtocol
    {
        return $this->transport;
    }

    /**
     * Prepare response on notification request
     *
     * @param int $errorCode
     *
     * @return Response
     */
    public function getNotificationResponse(int $errorCode = null): Response
    {
        return response($this->getTransport()->getNotificationResponse($this->response, $this->mapError($errorCode)));
    }

    /**
     * Prepare response on check request
     *
     * @param int $errorCode
     *
     * @return Response
     */
    public function getCheckResponse(int $errorCode = null): Response
    {
        return response($this->getTransport()->getNotificationResponse($this->response, $this->mapError($errorCode)));
    }

    /**
     * Get specific error code
     *
     * @param int $error
     *
     * @return int
     */
    protected function mapError(int $error): int
    {
        $map = [
            self::RESPONSE_SUCCESS => 0,
            self::RESPONSE_ERROR   => 1,
        ];

        return $map[$error] ?? $map[self::RESPONSE_ERROR];
    }

    /**
     * Get last error code
     *
     * @return int
     */
    public function getLastError(): int
    {
        return 0;
    }

    /**
     * Get param by name
     *
     * @param string $name
     *
     * @return mixed
     */
    public function getParam(string $name)
    {
        return $this->getResponseParam($name);
    }

    /**
     * Get pay service options
     *
     * @return array
     */
    public function getOptions(): array
    {
        return [
            (new PayServiceOption())->setType(PayServiceOption::TYPE_STRING)->setLabel('Public Id')->setAlias('publicId'),
            (new PayServiceOption())->setType(PayServiceOption::TYPE_STRING)->setLabel('Secret key')->setAlias('secretKey'),
        ];
    }

    /**
     * Create schedule
     *
     * @return RecurringSchedule|Schedule
     */
    public function schedule(): RecurringSchedule
    {
        return new Schedule();
    }

    /**
     * Create schedule.
     *
     * @param RecurringSchedule $schedule
     *
     * @return string Schedule id/token
     */
    public function saveSchedule(RecurringSchedule $schedule): string
    {
        if (!empty($schedule->getId())) {
            $this->getCloudPaymentsProtocol()->updateSchedule($schedule->getId(), $schedule->toArray());
        } else {
            $this->getCloudPaymentsProtocol()->createSchedule($schedule->toArray());
        }

        return $schedule->getId();
    }

    /**
     * Remove schedule
     *
     * @param string $token
     *
     * @return bool
     */
    public function removeSchedule(string $token): bool
    {
        return $this->getCloudPaymentsProtocol()->removeSchedule($token);
    }

    /**
     * Get schedule by id
     *
     * @param string $id
     *
     * @return RecurringSchedule
     */
    public function getSchedule(string $id): RecurringSchedule
    {
        $data = $this->getCloudPaymentsProtocol()->getSchedule($id);

        return $this->fillSchedule($data['Model']);
    }

    /**
     * Create and fill schedule
     *
     * @param array $data
     *
     * @return RecurringSchedule
     */
    protected function fillSchedule(array $data): RecurringSchedule
    {
        return $this->schedule()
            ->setPeriod($data['Period'], $data['Interval'])
            ->setId($data['Id'])
            ->setMaxPayments((int)$data['MaxPeriods'])
            ->setAccountId($data['AccountId'])
            ->setDescription($data['Description'])
            ->setEmail($data['Email'])
            ->setAmount($data['Amount'])
            ->setCurrency($data['Currency'])
            ->needConfirmation($data['RequireConfirmation'])
            ->setStartDate($data['StartDateIso']);
    }

    /**
     * Get list of schedules
     *
     * @param string|null $accountId
     *
     * @return array|[]RecurringSchedule
     */
    public function getAllSchedules(string $accountId = null): array
    {
        return array_map(function (array $item) {
            return $this->fillSchedule($item['Model']);
        }, $this->getCloudPaymentsProtocol()->getScheduleList($accountId));
    }

    /**
     * @return bool
     */
    public function getUseWidget(): bool
    {
        return $this->useWidget;
    }

    /**
     * @param mixed $useWidget
     *
     * @return CloudPaymentsDriver
     */
    public function useWidget(bool $useWidget): self
    {
        $this->useWidget = $useWidget;

        return $this;
    }
}