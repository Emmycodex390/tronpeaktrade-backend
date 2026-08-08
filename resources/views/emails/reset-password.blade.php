@component('mail::message')
# Reset your password

We received a request to reset the password on your TronPeak Trade
account. Click the button below to choose a new one.

@component('mail::button', ['url' => $url, 'color' => 'primary'])
Reset Password
@endcomponent

This link expires in {{ $expiresMinutes }} minutes. If you didn't
request a password reset, no action is needed — your password won't
change unless you click the link above and set a new one.

If the button doesn't work, copy and paste this URL into your browser:
{{ $url }}

Thanks,<br>
TronPeak Trade
@endcomponent