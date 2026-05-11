<x-mail::message>
# Verify your email address

Click the button below to verify your email. This link expires in {{ config('innertia.auth.email_verification.ttl', 60) }} minutes.

<x-mail::button :url="$url">
Verify Email
</x-mail::button>

If you did not create an account, no further action is required.
</x-mail::message>
