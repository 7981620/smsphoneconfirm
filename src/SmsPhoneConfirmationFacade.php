<?php

namespace Agenta\SmsPhoneConfirmation;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Agenta\SmsPhoneConfirmation\Skeleton\SkeletonClass
 */
class SmsPhoneConfirmationFacade extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'smsphoneconfirmation';
    }
}
