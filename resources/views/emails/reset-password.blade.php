<p>Hi {{ $name }},</p>

<p>Somebody asked to set a new password for your account on {{ $appName }}. If that was you, please click the link below to choose one:</p>

<p><a href="{{ $resetUrl }}">Choose a new password</a></p>

<p>The link works once and expires in {{ $minutes }} minutes. Setting a new password signs you out everywhere. If your account has two-step verification it stays on, you will still be asked for a code when you sign in.</p>

<p>If this was not you, you can safely ignore this e-mail: your password has not been changed.</p>
