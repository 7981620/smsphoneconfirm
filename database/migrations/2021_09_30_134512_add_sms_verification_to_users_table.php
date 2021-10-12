<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSmsVerificationToUsersTable extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            Schema::table('users', function (Blueprint $table) {
                $table->char('sms_code', 8)->nullable()->comment('Код подтверждения номера телефона (SMS) - максимум 8 символов');
                $table->dateTime('sms_sended_at')->nullable()->comment('Дата и время отправки кода SMS');
                $table->dateTime('sms_repeat_at')->nullable()->comment('Дата и время когда можно повторить отправку кода SMS');
                $table->integer('sms_confirm_retry')->nullable()->comment('Неправильных попыток подтверждения');
                $table->integer('sms_send_count')->nullable()->comment('Кол-во отправок SMS');
                $table->dateTime('phone_verified_at')->nullable()->comment('Когда был подтвержден мобильный');
            });
        });
    }

    public function down()
    {

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('sms_code');
            $table->dropColumn('sms_sended_at');
            $table->dropColumn('sms_repeat_at');
            $table->dropColumn('sms_confirm_retry');
            $table->dropColumn('sms_send_count');
            $table->dropColumn('phone_verified_at');
        });

    }
}
