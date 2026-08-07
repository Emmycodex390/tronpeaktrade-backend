@component('mail::message')
# Confirm your withdrawal

Your **{{ $planName }}** investment (${{ $amount }}) has matured, and you've
requested to withdraw it.

@if($label)
This confirmation was requested specifically: **{{ $label }}**
@endif

To confirm this is really you, enter the code below on the withdrawal
screen:

@component('mail::panel')
<div style="font-size: 28px; font-weight: 700; letter-spacing: 4px; text-align: center;">
{{ $code }}
</div>
@endcomponent

This code expires in 30 minutes and can only be used once. If you didn't
request this withdrawal, you can safely ignore this email — no funds will
move without the correct code.

Thanks,<br>
TronPeak Trade
@endcomponent