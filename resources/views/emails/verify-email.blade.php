@component('mail::message')
# Verify your email

Thanks for signing up with TronPeak Trade. Click the button below to
confirm this is your email address.

@component('mail::button', ['url' => $url, 'color' => 'primary'])
Verify Email Address
@endcomponent

This link expires in {{ $expiresMinutes }} minutes. If you didn't
create an account, you can safely ignore this email.

If the button doesn't work, copy and paste this URL into your browser:
{{ $url }}

Thanks,<br>
TronPeak Trade
@endcomponent
