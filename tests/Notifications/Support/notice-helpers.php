<?php

use Illuminate\Support\Facades\Hash;

if (! class_exists('NoticeTestUser')) {
    /** Concrete lib User on the lib `users` table (app mode migrations). */
    class NoticeTestUser extends \Innertia\Auth\Models\User
    {
        protected $table = 'users';
        protected $guarded = [];
    }
}

if (! function_exists('noticeSetUp')) {
    function noticeSetUp(): void
    {
        config()->set('innertia.auth.user_model', NoticeTestUser::class);
        config()->set('auth.providers.users.model', NoticeTestUser::class);
        config()->set('app.frontend_url', 'https://app.test');
        config()->set('innertia.mail.verify_logo', false);
        \Innertia\Notifications\NoticeTypes::flush();
        \Innertia\Notifications\Preferences::flush();
        \Innertia\Mail\MailSettings::flush();
        \Innertia\Mail\EmailLogging::reset();
        \Illuminate\Support\Facades\Cache::flush();
    }
}

if (! function_exists('noticeUser')) {
    function noticeUser(array $attrs = []): NoticeTestUser
    {
        $user = (new NoticeTestUser)->forceFill($attrs + [
            'name'     => 'Ana',
            'email'    => 'ana' . uniqid() . '@test.cl',
            'password' => Hash::make('Secreta123!'),
        ]);
        $user->save();

        return $user->refresh();
    }
}
