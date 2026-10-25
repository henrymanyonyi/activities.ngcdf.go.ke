{{-- Action-needed email (App\Mail\AlertMessage). The reference and the step only; the details stay in FAPM. --}}
@use('App\Support\DocumentFont')
<!doctype html>
<html lang="en">
<body style="margin:0;padding:24px;background:#f8fafc;font-family:{{ DocumentFont::STACK }};color:#1e293b;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#ffffff;border:1px solid #e2e8f0;border-radius:8px;">
        <tr><td style="padding:16px 24px;border-bottom:2px solid #059669;">
            @if ($fromOffice)
                <p style="margin:0;font-size:15px;font-weight:700;color:#065f46;letter-spacing:.5px;">OFFICE OF THE CHIEF EXECUTIVE OFFICER</p>
                <p style="margin:2px 0 0;font-size:12px;color:#065f46;">NG-CDF Board · Field Activity Planning and Monitoring</p>
            @else
                <p style="margin:0;font-size:13px;font-weight:700;color:#065f46;">NG-CDF Board · Field Activity Planning and Monitoring</p>
            @endif
            <p style="margin:2px 0 0;font-size:11px;font-weight:700;letter-spacing:1.5px;color:#b91c1c;">RESTRICTED</p>
        </td></tr>
        <tr><td style="padding:20px 24px;font-size:14px;line-height:1.55;">
            @foreach (preg_split("/\n\n+/", $text) as $paragraph)
                <p style="margin:0 0 12px;">{{ $paragraph }}</p>
            @endforeach
            @if ($link !== '')
            <p style="margin:20px 0;"><a href="{{ $link }}" style="display:inline-block;background:#059669;color:#ffffff;text-decoration:none;font-weight:600;padding:10px 18px;border-radius:6px;">{{ $forAccount ? 'Sign in to FAPM' : 'Open the memo request' }}</a></p>
            <p style="margin:0;font-size:12px;color:#64748b;">If the button does not work, open {{ $link }}</p>
            @endif
        </td></tr>
        <tr><td style="padding:14px 24px;border-top:1px solid #e2e8f0;font-size:11px;color:#94a3b8;line-height:1.5;">
            @if ($forAccount)
                For confidentiality this message gives only the reference. The details are in FAPM, after you sign in. Do not forward it. You receive it because you are an authorised FAPM user; it was sent automatically, so please do not reply.
            @else
                The link is for you alone: it asks for a code sent to your email or mobile number before it opens, so do not forward it. You receive this because your Head of Department asked you to prepare an activity memo in FAPM. It was sent automatically, so please do not reply.
            @endif
        </td></tr>
    </table>
</body>
</html>
