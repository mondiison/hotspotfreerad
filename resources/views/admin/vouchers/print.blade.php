<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $batch->name }} Vouchers</title>
    @php
        $columns = max(2, min(5, (int) ($columns ?? 3)));
        $printStatus = in_array(($printStatus ?? 'all'), ['all', 'unused', 'used'], true) ? $printStatus : 'all';
        $statusLabel = ['all' => 'All codes', 'unused' => 'Unused only', 'used' => 'Used only'][$printStatus];
        $voucherMinHeight = match ($columns) {
            2 => '58mm',
            4 => '35mm',
            5 => '29mm',
            default => '44mm',
        };
    @endphp
    <style>
        @page { size: A4; margin: 8mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, sans-serif; color: #111827; }
        .toolbar { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 10px; font-size: 12px; }
        .toolbar form { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
        .toolbar button, .toolbar select { border: 1px solid #d4d4d8; border-radius: 6px; padding: 8px 10px; }
        .toolbar button { background: #111827; color: #fff; cursor: pointer; }
        .toolbar select { background: #fff; color: #111827; }
        .sheet { display: grid; grid-template-columns: repeat({{ $columns }}, minmax(0, 1fr)); gap: {{ $columns >= 4 ? '3mm' : '6mm' }}; }
        .voucher { min-height: {{ $voucherMinHeight }}; border: 1px dashed #71717a; border-radius: 6px; padding: {{ $columns >= 4 ? '3mm' : '4mm' }}; break-inside: avoid; display: flex; flex-direction: column; justify-content: space-between; }
        .brand { font-size: 12px; font-weight: 700; text-transform: uppercase; }
        .shop { margin-top: 2px; font-size: 10px; color: #52525b; }
        .code { margin: 8px 0; padding: 7px; border-radius: 4px; background: #111827; color: #fff; text-align: center; font-size: {{ $columns >= 4 ? '12px' : '17px' }}; font-weight: 700; letter-spacing: .5px; overflow-wrap: anywhere; }
        .meta { display: grid; gap: 2px; font-size: 10px; color: #3f3f46; }
        .foot { margin-top: 6px; font-size: 9px; color: #71717a; }
        .status-used { border-color: #a1a1aa; background: #f4f4f5; }
        .status-used .code { background: #52525b; }
        @media print {
            .toolbar { display: none; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <div>
            <strong>{{ $batch->name }}</strong>
            <span>{{ $batch->vouchers->count() }} vouchers / {{ $batch->shop->name }} / {{ $statusLabel }}</span>
        </div>
        <form method="GET">
            <label>
                Columns
                <select name="columns" onchange="this.form.submit()">
                    @foreach ([2, 3, 4, 5] as $columnOption)
                        <option value="{{ $columnOption }}" @selected($columns === $columnOption)>{{ $columnOption }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                Codes
                <select name="status" onchange="this.form.submit()">
                    <option value="all" @selected($printStatus === 'all')>All</option>
                    <option value="unused" @selected($printStatus === 'unused')>Unused</option>
                    <option value="used" @selected($printStatus === 'used')>Used</option>
                </select>
            </label>
            <button type="button" onclick="window.print()">Print / Save PDF</button>
        </form>
    </div>

    <main class="sheet">
        @foreach ($batch->vouchers as $voucher)
            <article class="voucher status-{{ $voucher->status }}">
                <div>
                    <div class="brand">{{ $batch->shop->tenant->company_name }}</div>
                    <div class="shop">{{ $batch->shop->name }}</div>
                    <div class="code">{{ $voucher->code }}</div>
                    <div class="meta">
                        <span>{{ $batch->package->name }}</span>
                        <span>Speed: {{ $batch->package->speed_limit_profile }}</span>
                        <span>Valid: {{ round($batch->package->limit_uptime_seconds / 3600, 1) }} hours</span>
                        @if ($voucher->status === 'used')
                            <span>Used: {{ $voucher->used_at?->format('M j, Y H:i') ?? 'Yes' }}</span>
                        @endif
                    </div>
                </div>
                <div class="foot">Connect to Wi-Fi, open the portal, choose voucher, and enter this code. One device only.</div>
            </article>
        @endforeach

        @if ($batch->vouchers->isEmpty())
            <p>No vouchers match this print filter.</p>
        @endif
    </main>
</body>
</html>
