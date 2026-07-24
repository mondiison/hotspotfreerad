<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $batch->name }} Vouchers</title>
    <style>
        @page { size: A4; margin: 8mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, sans-serif; color: #111827; }
        .toolbar { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 10px; font-size: 12px; }
        .toolbar button { border: 1px solid #d4d4d8; background: #111827; color: #fff; border-radius: 6px; padding: 8px 12px; cursor: pointer; }
        .sheet { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6mm; }
        .voucher { min-height: 44mm; border: 1px dashed #71717a; border-radius: 6px; padding: 4mm; break-inside: avoid; display: flex; flex-direction: column; justify-content: space-between; }
        .brand { font-size: 12px; font-weight: 700; text-transform: uppercase; }
        .shop { margin-top: 2px; font-size: 10px; color: #52525b; }
        .code { margin: 8px 0; padding: 7px; border-radius: 4px; background: #111827; color: #fff; text-align: center; font-size: 17px; font-weight: 700; letter-spacing: 1px; }
        .meta { display: grid; gap: 2px; font-size: 10px; color: #3f3f46; }
        .foot { margin-top: 6px; font-size: 9px; color: #71717a; }
        @media print {
            .toolbar { display: none; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <div>
            <strong>{{ $batch->name }}</strong>
            <span>{{ $batch->vouchers->count() }} vouchers / {{ $batch->shop->name }}</span>
        </div>
        <button type="button" onclick="window.print()">Print / Save PDF</button>
    </div>

    <main class="sheet">
        @foreach ($batch->vouchers as $voucher)
            <article class="voucher">
                <div>
                    <div class="brand">{{ $batch->shop->tenant->company_name }}</div>
                    <div class="shop">{{ $batch->shop->name }}</div>
                    <div class="code">{{ $voucher->code }}</div>
                    <div class="meta">
                        <span>{{ $batch->package->name }}</span>
                        <span>Speed: {{ $batch->package->speed_limit_profile }}</span>
                        <span>Valid: {{ round($batch->package->limit_uptime_seconds / 3600, 1) }} hours</span>
                    </div>
                </div>
                <div class="foot">Connect to Wi-Fi, open the portal, choose voucher, and enter this code. One device only.</div>
            </article>
        @endforeach
    </main>
</body>
</html>
