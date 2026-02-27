<x-mail::message>
# You've Been Invited!

Hi there,

**{{ $inviter_name }}** has invited you to join the **{{ $team_name }}** team at **{{ $organization_name }}**.

@if (!$is_existing_user)
You'll need to create an account to accept this invitation and start collaborating with your team.
@else
Click the button below to accept the invitation and join the team.
@endif

<x-mail::button :url="$accept_url">
@if (!$is_existing_user)
Create Account & Join Team
@else
Accept Invitation
@endif
</x-mail::button>

This invitation will expire on **{{ $expires_at }}**.

If you did not expect this invitation, you can safely ignore this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
