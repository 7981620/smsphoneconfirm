<?php

namespace Agenta\SmsPhoneConfirmation;

use Agenta\SmsClubService\SmsClubService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class SmsPhoneConfirmation
{

    protected int $smsMaxRetry = 3;
    protected int $smsSendMaxCount = 3;
    protected int $smsSendPauseSeconds = 20;
    protected int $smsCodeLenght = 4;
    protected $gate;


    /**
     * Высылает код на номер телефона юзера
     *
     * @param User $user
     * @param string $preMessage
     * @return bool|string
     * @throws \Exception
     */
    private function sendCodeToPhone(User $user, string $preMessage): bool|string
    {

        $sms_code = substr(str_shuffle('0123456789'), 0, $this->smsCodeLenght);

        if ($this->gate->sendMessage(
            [$user->phone],
            $preMessage . ' ' . $sms_code)
        ) {

            $sendCount = $user->sms_send_count;
            if (is_null($sendCount)) {
                $sendCount = 0;
            }

            try {
                $user->sms_code = $sms_code;
                $user->sms_sended_at = now()->toDateTimeString();
                $user->sms_send_count = $sendCount + 1;
                $user->sms_confirm_retry = 0;
                $user->sms_repeat_at = now()
                    ->addSeconds($this->smsSendPauseSeconds)
                    ->toDateTimeString();
                $user->save();
            } catch (\Exception $exception) {
                Log::error('sendCodeToPhone save info to user DB: ' . $exception->getMessage());
                return false;
            }

            return $sms_code;


        }

        return false;

    }


    /**
     * Проверка введенного кода подтверждения
     *
     * @param User $user
     * @param string $sms_code
     * @return array
     */
    public function smsConfirmation(User $user, string $sms_code): array
    {

        if ($this->checkIsConfirmed($user)) {
            return [
                'status' => 'error',
                'description' => [
                    'type' => 'already_confirmed',
                    'value' => null,
                    'message' => 'Номер уже был подтвержден ' . Carbon::parse($user->phone_verified_at)->format('d.m.Y в H:i:s')
                ]
            ];
        }


        if (!$user->sms_code) {
            return [
                'status' => 'error',
                'description' => [
                    'type' => 'no_sms_code',
                    'value' => null,
                    'message' => 'Код смс не найден у пользователя'
                ]
            ];
        }

        //проверка, что код есть и не исчерпано время на ввод и попытки
        if (!$this->checkIsMaxRetry($user)) {


            if ($this->checkCodeLenght($sms_code)) {

                if ($user->sms_code === $sms_code) {

                    $user->sms_code = null;
                    $user->phone_verified_at = now()->toDateTimeString();
                    $user->sms_confirm_retry = null;
                    $user->sms_send_count = null;
                    $user->sms_repeat_at = null;
                    $user->sms_sended_at = null;
                    $user->save();

                    return [
                        'status' => 'success',
                        'description' => [
                            'type' => 'phone_verified',
                            'value' => null,
                            'message' => 'Успешное подтверждение номера телефона'
                        ]
                    ];

                }

                //ввели неправильный код потдверждения
                $user->sms_confirm_retry++;
                $user->save();

                $countRemaining = $this->smsMaxRetry - $user->sms_confirm_retry;

                if ($countRemaining > 0) {
                    return [
                        'status' => 'error',
                        'description' => [
                            'type' => 'sms_code_empty',
                            'value' => $countRemaining,
                            'message' => 'Вы ввели неправильный код, осталось попыток: ' . $countRemaining
                        ]
                    ];
                }

                return [
                    'status' => 'error',
                    'description' => [
                        'type' => 'sms_code_empty',
                        'value' => $countRemaining,
                        'message' => 'Неправильный код, получите новый.'
                    ]
                ];


            }

            return [
                'status' => 'error',
                'description' => [
                    'type' => 'sms_code_empty',
                    'value' => null,
                    'message' => 'Указан пустое значение в коде sms'
                ]
            ];


        }

        //попытки исчерапаны
        //проверим, что еще можно отправить новое SMS
        if (!$this->checkIsMaxSend($user)) {

            return [
                'status' => 'error',
                'description' => [
                    'type' => 'limit_retry',
                    'value' => null,
                    'message' => 'Количество попыток ввода текущего кода исчерпано - вы должны получить новый код'
                ]
            ];

        }

        //исчерпано кол-во отправок SMS
        return [
            'status' => 'error',
            'description' => [
                'type' => 'send_limit',
                'value' => $user->sms_send_count,
                'message' => 'Достигнут лимит отправки кодов SMS'
            ]
        ];


    }

    /**
     * Отправка кода SMS
     *
     * @param User $user
     * @param string $preMessage
     * @param bool $testMode
     * @return array
     * @throws \Exception
     */
    public function sendCode(User $user, string $preMessage, bool $testMode = false): array
    {

        $this->gate = new SmsClubService(3, 2000, $testMode);

        if (is_null($user->sms_verified_at)) {

            //уже были коды?
            if (!is_null($user->sms_code)) {

                //исчерпаны отправки SMS?
                if (!$this->checkIsMaxSend($user)) {
                    //нет, отправляю код
                    $timeLimit = $this->isRestrictedSendByTimelimit($user);

                    if ($timeLimit <= 0) {
                        $code = $this->sendCodeToPhone($user, $preMessage);
                        return [
                            'status' => 'success',
                            'description' => [
                                'type' => 'sended',
                                'value' => $code,
                                'message' => 'Код подтверждения успешно отправлен'
                            ]
                        ];
                    }

                    return [
                        'status' => 'error',
                        'description' => [
                            'type' => 'wait_before_send',
                            'value' => $timeLimit,
                            'message' => 'Подождите ' . $timeLimit . ' сек. перед отправкой нового кода'
                        ]
                    ];

                }

                //да, исчерпаны
                return [
                    'status' => 'error',
                    'description' => [
                        'type' => 'send_limit',
                        'value' => null,
                        'message' => 'Достигнут лимит отправки кодов SMS, запросите новый код.'
                    ]
                ];

            }

            //нет, отправляю самый первый
            if ($code = $this->sendCodeToPhone($user, $preMessage)) {
                return [
                    'status' => 'success',
                    'description' => [
                        'type' => 'sended',
                        'value' => $code,
                        'message' => 'Код подтверждения успешно отправлен'
                    ]
                ];
            }

            return [
                'status' => 'error',
                'description' => [
                    'type' => 'not_sended',
                    'value' => null,
                    'message' => 'Код не отправлен'
                ]
            ];

        }

        return [
            'status' => 'error',
            'description' => [
                'type' => 'already_confirmed',
                'value' => null,
                'message' => 'Номер уже был подтвержден ' . Carbon::parse($user->phone_verified_at)->format('d.m.Y в H:i:s')
            ]
        ];

    }


    /**
     * Проверяет что номер еще не подтвержден
     *
     * @param User $user
     * @return bool
     */
    public function checkIsConfirmed(User $user): bool
    {
        if (!is_null($user->phone_verified_at)) {
            return true;
        }

        return false;
    }


    /**
     * Проверка что не исчерпан лимит на попытку ввода кода
     *
     * @param User $user
     * @return bool
     */
    public function checkIsMaxRetry(User $user): bool
    {
        if ($user->sms_confirm_retry) {
            return $user->sms_confirm_retry >= $this->smsMaxRetry;
        }

        return false;

    }

    /**
     * Проверка лимита на отправку SMS
     *
     * @param User $user
     * @return bool
     */
    public function checkIsMaxSend(User $user): bool
    {

        if (!is_null($user->sms_send_count)) {
            return $user->sms_send_count === $this->smsSendMaxCount;
        }

        return false;
    }


    /**
     * Проверка что можно отправлять SMS по таймлимиту
     *
     * @param User $user
     * @return int 0 - можно отправлять, int - кол-во секунд паузы
     */
    public function isRestrictedSendByTimelimit(User $user): int
    {
        if (!is_null($user->sms_repeat_at)) {
            $expired = Carbon::parse($user->sms_repeat_at);
            $diffSeconds = now()->diffInSeconds($expired, false);

            if ($diffSeconds < $this->smsSendPauseSeconds) {
                return $diffSeconds;
            }

        }

        return 0;
    }


    /**
     * Проверка длины кода
     *
     * @param string $sms_code
     * @return bool
     */
    private function checkCodeLenght(string $sms_code): bool
    {
        return strlen($sms_code) === $this->smsCodeLenght;
    }

    /**
     * @return int
     */
    public function getSmsSendPauseSeconds(): int
    {
        return $this->smsSendPauseSeconds;
    }

    /**
     * @param int $smsSendPauseSeconds
     */
    public function setSmsSendPauseSeconds(int $smsSendPauseSeconds): void
    {
        $this->smsSendPauseSeconds = $smsSendPauseSeconds;
    }

    /**
     * @return int
     */
    public function getSmsMaxRetry(): int
    {
        return $this->smsMaxRetry;
    }

    /**
     * @param int $smsMaxRetry
     */
    public function setSmsMaxRetry(int $smsMaxRetry): void
    {
        $this->smsMaxRetry = $smsMaxRetry;
    }

    /**
     * @return int
     */
    public function getSmsSendMaxCount(): int
    {
        return $this->smsSendMaxCount;
    }

    /**
     * @param int $smsSendMaxCount
     */
    public function setSmsSendMaxCount(int $smsSendMaxCount): void
    {
        $this->smsSendMaxCount = $smsSendMaxCount;
    }

}
