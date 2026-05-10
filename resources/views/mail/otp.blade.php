<x-mail::message>

@if($action === 'login')
# Verification code to sign in
@else
# Your verification code
@endif

Use the following code to complete your request. It expires in **{{ config('innertia.auth.otp.ttl', 10) }} minutes**.

<x-mail::panel>
# {{ $code }}
</x-mail::panel>

If you did not request this code, you can safely ignore this email.

</x-mail::message>
