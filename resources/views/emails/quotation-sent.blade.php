<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quotation {{ $doc['number'] }}</title>
    <style>
        @media only screen and (max-width: 480px) {
            .shell-pad { padding: 20px 16px !important; }
            .card-pad { padding: 24px 20px !important; }
            .band-label, .band-amount { display: block !important; width: 100% !important; text-align: left !important; }
            .band-amount { padding-top: 6px !important; font-size: 24px !important; }
            .kv-label, .kv-value { display: block !important; width: 100% !important; text-align: left !important; }
        }
    </style>
</head>

<body style="margin:0;padding:0;background:#f4f4f5;font-family:Arial,Helvetica,sans-serif;color:#171717;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;">
        <tr>
            <td class="shell-pad" align="center" style="padding:32px 16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;">
                    <tr>
                        <td class="card-pad" style="padding:32px 36px 36px;">
                            <div style="font-size:22px;font-weight:bold;letter-spacing:0.18em;color:#171717;">TOWMATE</div>
                            <div style="height:4px;line-height:4px;font-size:0;background:#facc15;margin-top:16px;">&nbsp;</div>

                            <h1 style="margin:28px 0 12px;font-size:24px;line-height:1.25;color:#171717;">Your quotation is ready</h1>
                            <p style="margin:0 0 24px;font-size:16px;line-height:1.5;color:#171717;word-break:break-word;">
                                Hi {{ $doc['customer']['name'] }}, your tow service quotation is attached.
                            </p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-bottom:1px solid #d4d4d4;">
                                <tr>
                                    <td class="kv-label" style="padding:10px 0 14px;font-size:15px;color:#404040;">Quotation</td>
                                    <td class="kv-value" align="right" style="padding:10px 0 14px;font-size:15px;font-weight:bold;color:#171717;word-break:break-word;">{{ $doc['number'] }}</td>
                                </tr>
                            </table>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:22px;background:#facc15;">
                                <tr>
                                    <td class="band-label" style="padding:16px 18px;font-size:12px;font-weight:bold;letter-spacing:0.12em;color:#171717;">ESTIMATED TOTAL</td>
                                    <td class="band-amount" align="right" style="padding:16px 18px;font-size:26px;font-weight:bold;color:#171717;">{{ $doc['total'] }}</td>
                                </tr>
                            </table>

                            <p style="margin:22px 0 0;font-size:15px;line-height:1.5;color:#171717;word-break:break-word;">
                                <strong>Attached:</strong> {{ $attachmentName }}
                            </p>
                            <p style="margin:12px 0 0;font-size:14px;line-height:1.5;color:#404040;">
                                Open the TowMate app to review and accept this quotation.@if ($expiresAt) It is valid until {{ $expiresAt }}.@endif
                            </p>

                            <div style="margin-top:28px;padding-top:18px;border-top:1px solid #d4d4d4;font-size:14px;line-height:1.5;color:#404040;">
                                {{ $doc['company']['name'] }} · {{ $doc['company']['phone'] }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>

</html>
