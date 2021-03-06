<?php namespace professionalweb\payment\interfaces;

use professionalweb\payment\contracts\PayProtocol;

/**
 * Interface for service to work with cloudpayments protocol
 * @package professionalweb\payment\interfaces
 */
interface CloudPaymentProtocol extends PayProtocol
{
    /**
     * Get list of schedules
     *
     * @param string $accountId
     *
     * @return array
     */
    public function getScheduleList(string $accountId): array;

    /**
     * Get schedule by id
     *
     * @param string $id
     *
     * @return array
     */
    public function getSchedule(string $id): array;

    /**
     * Create schedule
     *
     * @param array $data
     *
     * @return string
     */
    public function createSchedule(array $data): string;

    /**
     * Save schedule
     *
     * @param string $id
     * @param array  $data
     *
     * @return bool
     */
    public function updateSchedule(string $id, array $data): bool;

    /**
     * Remove schedule by id
     *
     * @param string $id
     *
     * @return bool
     */
    public function removeSchedule(string $id): bool;

    /**
     * Payment by card token
     *
     * @param array $data
     *
     * @return array
     */
    public function paymentByToken(array $data): array;
}