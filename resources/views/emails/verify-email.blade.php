<p>Your e-mail address ({{ $email }}) was added to an account on {{ $appName }}. We're sending this e-mail to confirm the address belongs to whoever did this.</p>

<p>If you own this address and requested this change yourself then please click the link below to confirm your address:</p>

<p><a href="{{ $verifyUrl }}">Verify my e-mail address</a></p>

<p>If this was in fact not done by you you can safely ignore this email. In case you are being spammed with messages from us you can <a href="{{ $blockUrl }}">click this link</a> to stop receiving unwanted verification e-mails permanently.</p>

<p>Both of the links above will expire on {{ $expiresAt->format('Y-m-d') }} at {{ $expiresAt->format('g:i:s a T') }}, after which you will need to manually resend this confirmation message through the site.</p>
