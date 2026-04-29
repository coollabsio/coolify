<!DOCTYPE html>
<html lang="en">
<body style="margin:0;padding:0;background-color:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#0f172a;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#f4f4f5;padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="640" cellspacing="0" cellpadding="0" border="0" style="max-width:640px;width:100%;background-color:#ffffff;border:1px solid #e4e4e7;border-radius:12px;">
                    <tr>
                        <td style="padding:40px 40px 8px 40px;">
                            <p style="margin:0 0 8px 0;font-size:16px;line-height:1.6;color:#0f172a;font-weight:700;">Dear Coolify Cloud Customer,</p>

                            <p style="margin:0 0 24px 0;font-size:15px;line-height:1.6;color:#0f172a;">
                                @if ($daysFromNow <= 0)
                                    <strong>Today</strong> we will perform a scheduled maintenance to migrate Coolify Cloud from <strong>v4 to v5</strong>. The maintenance window:
                                @elseif ($daysFromNow === 1)
                                    <strong>Tomorrow</strong> we will perform a scheduled maintenance to migrate Coolify Cloud from <strong>v4 to v5</strong>. The maintenance window:
                                @else
                                    In <strong>{{ $daysFromNow }} days</strong> we will perform a scheduled maintenance to migrate Coolify Cloud from <strong>v4 to v5</strong>. The maintenance window:
                                @endif
                            </p>

                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin:0 0 16px 0;border:1px solid #e2e8f0;border-radius:8px;background-color:#f8fafc;">
                                <tr>
                                    <td width="50%" style="padding:16px 20px;border-right:1px solid #e2e8f0;vertical-align:top;">
                                        <div style="font-size:12px;line-height:1.4;color:#64748b;font-weight:700;margin-bottom:6px;">START TIME</div>
                                        <div style="font-size:15px;line-height:1.4;color:#0f172a;font-weight:700;">{{ $startUtcLong }}</div>
                                    </td>
                                    <td width="50%" style="padding:16px 20px;vertical-align:top;">
                                        <div style="font-size:12px;line-height:1.4;color:#64748b;font-weight:700;margin-bottom:6px;">END TIME</div>
                                        <div style="font-size:15px;line-height:1.4;color:#0f172a;font-weight:700;">{{ $endUtcLong }}</div>
                                    </td>
                                </tr>
                                <tr>
                                    <td colspan="2" style="padding:12px 20px;border-top:1px solid #e2e8f0;font-size:13px;line-height:1.5;color:#64748b;">
                                        Central European time: <strong style="color:#0f172a;">{{ $startEu }} to {{ $endEu }} {{ $euAbbr }}</strong>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:0 0 8px 0;font-size:15px;line-height:1.6;color:#0f172a;">
                                During the {{ $durationMinutes }}-minute window (it may complete sooner), the Coolify Cloud dashboard at <a href="https://app.coolify.io" style="color:#6b16ed;text-decoration:underline;">app.coolify.io</a> and the API at <a href="https://app.coolify.io/api" style="color:#6b16ed;text-decoration:underline;">app.coolify.io/api</a> will be offline and inaccessible.
                            </p>

                            <h2 style="margin:32px 0 12px 0;font-size:17px;line-height:1.4;font-weight:700;color:#0f172a;">How will this affect my service?</h2>
                            @if ($isInactive)
                                <p style="margin:0 0 24px 0;font-size:15px;line-height:1.6;color:#0f172a;">Your account is inactive (no active subscription for 90+ days). <strong>Unless you resubscribe before the maintenance window, your account will not be migrated to v5 and your data will be permanently deleted.</strong></p>
                            @else
                                <ul style="margin:0 0 24px 0;padding-left:20px;font-size:15px;line-height:1.7;color:#0f172a;">
                                    @if ($hasInactiveTeams)
                                        <li>One or more of your teams are inactive (no active subscription for 90+ days). <strong>Inactive teams will not be migrated to v5 and their data will be permanently deleted.</strong></li>
                                    @endif
                                    <li>All your existing deployments, resources and servers will keep running as normal, <strong>so your public websites and services will continue to be fully accessible.</strong></li>
                                    <li>No deployments can be triggered during the maintenance window (this includes auto-deployments on commits).</li>
                                    <li>No configuration changes can be made during the maintenance window.</li>
                                    <li>You will be logged out and need to re-authenticate on your next visit.</li>
                                </ul>
                            @endif

                            <h2 style="margin:32px 0 12px 0;font-size:17px;line-height:1.4;font-weight:700;color:#0f172a;">Are there any required actions I need to take?</h2>

                            @if ($isInactive)
                                <p style="margin:0 0 24px 0;font-size:15px;line-height:1.6;color:#0f172a;"><strong>Yes.</strong> Resubscribe before the maintenance window to keep your account: <a href="{{ $subscriptionNewUrl }}" style="color:#6b16ed;text-decoration:underline;">resubscribe here</a>.</p>
                            @else
                                @if ($hasInactiveTeams)
                                    <p style="margin:0 0 8px 0;font-size:15px;line-height:1.6;color:#0f172a;">Before the maintenance window: <strong>Yes.</strong></p>
                                    <ul style="margin:0 0 16px 0;padding-left:20px;font-size:15px;line-height:1.7;color:#0f172a;">
                                        <li>To keep your inactive team(s) and their data, resubscribe before the maintenance window: <a href="{{ $subscriptionUrl }}" style="color:#6b16ed;text-decoration:underline;">manage your subscription</a>.</li>
                                        <li>A few recommended preparations are also listed in the <a href="#" style="color:#6b16ed;text-decoration:underline;">v5 preparation guide</a>.</li>
                                    </ul>
                                @else
                                    <p style="margin:0 0 8px 0;font-size:15px;line-height:1.6;color:#0f172a;">Before the maintenance window: <strong>No.</strong></p>
                                    <ul style="margin:0 0 16px 0;padding-left:20px;font-size:15px;line-height:1.7;color:#0f172a;">
                                        <li>A few recommended preparations are listed in the <a href="#" style="color:#6b16ed;text-decoration:underline;">v5 preparation guide</a>.</li>
                                    </ul>
                                @endif

                                <p style="margin:0 0 8px 0;font-size:15px;line-height:1.6;color:#0f172a;">After the maintenance window: <strong>Yes</strong> (~10 minutes of work for most users):</p>
                                <ul style="margin:0 0 8px 0;padding-left:20px;font-size:15px;line-height:1.7;color:#0f172a;">
                                    <li><a href="https://app.coolify.io/login" style="color:#6b16ed;text-decoration:underline;">Login</a> again.</li>
                                    <li>Update all your servers from the dashboard.</li>
                                    <li>Check each app's configuration for correctness.</li>
                                    <li>Redeploy each application once to move it to the new deployment architecture (auto-deployments on commits are disabled until this step is completed).</li>
                                </ul>
                                <p style="margin:0 0 24px 0;font-size:15px;line-height:1.6;color:#0f172a;">Full details in the <a href="#" style="color:#6b16ed;text-decoration:underline;font-weight:700;">v5 upgrade guide</a>.</p>
                            @endif

                            <h2 style="margin:32px 0 12px 0;font-size:17px;line-height:1.4;font-weight:700;color:#0f172a;">Can I reschedule or avoid this maintenance window?</h2>
                            <p style="margin:0 0 24px 0;font-size:15px;line-height:1.6;color:#0f172a;">
                                No. This is a required migration to a more reliable, modern and improved Coolify architecture and applies to all Cloud customers.
                            </p>

                            <h2 style="margin:32px 0 12px 0;font-size:17px;line-height:1.4;font-weight:700;color:#0f172a;">Questions?</h2>
                            <p style="margin:0 0 24px 0;font-size:15px;line-height:1.6;color:#0f172a;">
                                Reach out to us in the <a href="#" style="color:#6b16ed;text-decoration:underline;font-weight:700;">migration</a> channel on the <a href="#" style="color:#6b16ed;text-decoration:underline;">coolLabs Discord</a> or simply reply to this email.
                            </p>

                            <p style="margin:32px 0 0 0;font-size:15px;line-height:1.6;color:#0f172a;">Thanks,<br>The Coolify Team</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px 40px 32px 40px;border-top:1px solid #f1f5f9;">
                            <p style="margin:0;font-size:12px;line-height:1.5;color:#64748b;">You're receiving this email because you have a Coolify Cloud account at <a href="https://coolify.io" style="color:#64748b;text-decoration:underline;">coolify.io</a>.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
