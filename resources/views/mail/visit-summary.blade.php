<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f3f4f6; margin: 0; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; background: #fff; border-radius: 12px; overflow: hidden; }
        /* S11 (#1718): brand accent aligned to drjsk.com.au (soft blue-grey /
           refined neutral) instead of the hardcoded emerald. */
        .header { background: #3f5b74; color: #fff; padding: 24px 32px; }
        .header h1 { margin: 0; font-size: 20px; font-weight: 600; }
        .header p { margin: 8px 0 0; font-size: 14px; opacity: 0.9; }
        .body { padding: 32px; }
        .section { margin-bottom: 24px; }
        .section-title { font-size: 14px; font-weight: 600; color: #3f5b74; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 8px; }
        .section-content { font-size: 15px; line-height: 1.6; color: #374151; }
        .medication { background: #f1f5f9; border-left: 3px solid #3f5b74; padding: 12px 16px; margin-bottom: 8px; border-radius: 0 8px 8px 0; }
        .medication-name { font-weight: 600; color: #33495c; }
        .medication-dose { font-size: 14px; color: #374151; }
        .cta { text-align: center; margin: 32px 0; }
        .cta a { display: inline-block; background: #3f5b74; color: #fff; padding: 12px 32px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 15px; }
        .footer { text-align: center; padding: 20px 32px; font-size: 12px; color: #9ca3af; border-top: 1px solid #e5e7eb; }
        .footer a { color: #3f5b74; text-decoration: none; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Your Visit Summary</h1>
            <p>{{ $visit->started_at ? \Carbon\Carbon::parse($visit->started_at)->format('j F Y') : 'Recent visit' }}
                @if($visit->practitioner)
                    &mdash; Dr. {{ $visit->practitioner->first_name }} {{ $visit->practitioner->last_name }}
                @endif
            </p>
        </div>

        <div class="body">
            @if($visit->reason_for_visit)
            <div class="section">
                <div class="section-title">Reason for Visit</div>
                <div class="section-content">{{ $visit->reason_for_visit }}</div>
            </div>
            @endif

            @if($visit->visitNote)
                {{-- S12 (#1718): patient-friendly section headings instead of raw
                     SOAP labels. Clinician content is unchanged - only the
                     heading framing is patient-oriented. --}}
                @if($visit->visitNote->assessment)
                <div class="section">
                    <div class="section-title">What we found</div>
                    <div class="section-content">{!! nl2br(e($visit->visitNote->assessment)) !!}</div>
                </div>
                @endif

                @if($visit->visitNote->plan)
                <div class="section">
                    <div class="section-title">Your care plan</div>
                    <div class="section-content">{!! nl2br(e($visit->visitNote->plan)) !!}</div>
                </div>
                @endif

                @if($visit->visitNote->follow_up)
                <div class="section">
                    <div class="section-title">Next steps and follow-up</div>
                    <div class="section-content">{!! nl2br(e($visit->visitNote->follow_up)) !!}</div>
                </div>
                @endif
            @endif

            @if($visit->prescriptions && $visit->prescriptions->isNotEmpty())
            <div class="section">
                <div class="section-title">Medications</div>
                @foreach($visit->prescriptions as $rx)
                <div class="medication">
                    <div class="medication-name">{{ $rx->medication?->display_name ?? $rx->medication?->generic_name ?? 'Medication' }}</div>
                    <div class="medication-dose">{{ $rx->dose_quantity }} {{ $rx->dose_unit }} &mdash; {{ $rx->frequency_text ?? $rx->frequency }}</div>
                    @if($rx->special_instructions)
                    <div class="medication-dose" style="margin-top:4px;font-style:italic;">{{ $rx->special_instructions }}</div>
                    @endif
                </div>
                @endforeach
            </div>
            @endif

            <div class="cta">
                <a href="{{ config('app.url') }}">View Full Details & Ask Questions</a>
            </div>

            <p style="font-size:14px;color:#6b7280;line-height:1.6;">
                Have questions about your visit? Log in to DrJSK AfterCare to chat with our AI assistant.
                It has your full visit context and can explain medical terms, medications, and your treatment plan.
            </p>
        </div>

        <div class="footer">
            <p>DrJSK AfterCare &mdash; AI-powered post-visit care</p>
            {{-- B6 (#1718): practice + emergency numbers are tappable tel: links. --}}
            <p>This is an automated summary. If you are concerned, call the practice on <a href="tel:+61293692800">(02) 9369 2800</a>. For medical emergencies, call <a href="tel:000">000</a>.</p>
        </div>
    </div>
</body>
</html>
