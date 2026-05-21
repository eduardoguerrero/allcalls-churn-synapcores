<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Churn Predictor — At-Risk Members</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <meta name="csrf-token" content="{{ csrf_token() }}">
</head>
<body class="bg-gray-50 min-h-screen">

{{-- Header --}}
<header class="bg-white border-b border-gray-200">
    <div class="max-w-7xl mx-auto px-6 py-5 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-gray-900 tracking-tight">Loyalty Churn Predictor</h1>
            <p class="text-sm text-gray-500 mt-0.5">At-risk Gold &amp; Platinum members · AllCalls.io</p>
        </div>
        @if ($members->isNotEmpty())
            <span class="inline-flex items-center gap-1.5 text-xs font-medium text-gray-500 bg-gray-100 px-3 py-1.5 rounded-full">
                <span class="w-2 h-2 rounded-full bg-green-500 inline-block"></span>
                Model active
            </span>
        @endif
    </div>
</header>

<main class="max-w-7xl mx-auto px-6 py-8">

    @if ($members->isEmpty())
        {{-- Empty state --}}
        <div class="flex flex-col items-center justify-center py-24 text-center">
            <div class="w-14 h-14 rounded-full bg-gray-100 flex items-center justify-center mb-4">
                <svg class="w-7 h-7 text-gray-400" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z"/>
                </svg>
            </div>
            <h2 class="text-base font-semibold text-gray-800">No predictions available</h2>
            <p class="text-sm text-gray-500 mt-1 max-w-xs">
                Seed the database and train the model to see at-risk members here.
            </p>
        </div>

    @else
        {{-- Summary cards --}}
        @php
            $avg = $members->avg('churn_probability');
            $high = $members->where('churn_probability', '>=', 0.7)->count();
        @endphp
        <div class="grid grid-cols-3 gap-4 mb-6">
            <div class="bg-white rounded-xl border border-gray-200 px-5 py-4">
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Members shown</p>
                <p class="text-2xl font-bold text-gray-900 mt-1">{{ $members->count() }}</p>
                <p class="text-xs text-gray-400 mt-0.5">Top 50 · Gold &amp; Platinum only</p>
            </div>
            <div class="bg-white rounded-xl border border-gray-200 px-5 py-4">
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Avg. churn probability</p>
                <p class="text-2xl font-bold text-gray-900 mt-1">{{ number_format($avg * 100, 1) }}%</p>
                <p class="text-xs text-gray-400 mt-0.5">Across displayed members</p>
            </div>
            <div class="bg-white rounded-xl border border-gray-200 px-5 py-4">
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">High risk ≥ 70%</p>
                <p class="text-2xl font-bold text-red-600 mt-1">{{ $high }}</p>
                <p class="text-xs text-gray-400 mt-0.5">Immediate action recommended</p>
            </div>
        </div>

        {{-- Table --}}
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-gray-800">At-risk members</h2>
                <span class="text-xs text-gray-400">Ranked by churn probability · descending</span>
            </div>
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-100">
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">ID</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Tier</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Tenure</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Visits 30d</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Spend 30d</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">P(Churn)</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    @foreach ($members as $member)
                        @php
                            $p = $member->churn_probability ?? 0;
                            $barColor  = $p >= 0.7 ? 'bg-red-500' : ($p >= 0.4 ? 'bg-amber-400' : 'bg-emerald-400');
                            $textColor = $p >= 0.7 ? 'text-red-600' : ($p >= 0.4 ? 'text-amber-600' : 'text-emerald-600');
                            $badgeColor = match($member->tier) {
                                \App\Enums\Tier::Platinum => 'bg-purple-50 text-purple-700 ring-1 ring-purple-200',
                                \App\Enums\Tier::Gold     => 'bg-amber-50 text-amber-700 ring-1 ring-amber-200',
                                default                   => 'bg-gray-100 text-gray-600',
                            };
                        @endphp
                        <tr class="hover:bg-gray-50 transition-colors" id="row-{{ $member->id }}">
                            <td class="px-5 py-3.5 text-gray-400 font-mono text-xs">#{{ $member->id }}</td>
                            <td class="px-5 py-3.5">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold {{ $badgeColor }}">
                                    {{ $member->tier->value }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5 text-gray-700">{{ $member->tenure_months }} mo.</td>
                            <td class="px-5 py-3.5 text-gray-700">{{ $member->visits_30d }}</td>
                            <td class="px-5 py-3.5 text-gray-700">${{ number_format($member->spend_30d, 2) }}</td>
                            <td class="px-5 py-3.5">
                                <div class="flex items-center gap-2.5">
                                    <div class="w-24 bg-gray-100 rounded-full h-1.5">
                                        <div class="{{ $barColor }} h-1.5 rounded-full transition-all"
                                             style="width: {{ round($p * 100) }}%"></div>
                                    </div>
                                    <span class="text-xs font-semibold font-mono {{ $textColor }} w-10">
                                        {{ number_format($p * 100, 1) }}%
                                    </span>
                                </div>
                            </td>
                            <td class="px-5 py-3.5">
                                <button
                                    onclick="sendOffer({{ $member->id }}, this)"
                                    class="px-3 py-1.5 text-xs font-medium bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 active:scale-95 transition-all disabled:opacity-50">
                                    Send offer
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

</main>

<script>
async function sendOffer(memberId, btn) {
    btn.disabled = true;
    btn.textContent = 'Sending…';

    try {
        const res = await fetch(`/api/members/${memberId}/offer`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
            },
        });

        if (res.ok) {
            btn.textContent = 'Sent ✓';
            btn.classList.replace('bg-indigo-600', 'bg-emerald-600');
            btn.classList.replace('hover:bg-indigo-700', 'hover:bg-emerald-700');
        } else {
            btn.textContent = 'Failed';
            btn.classList.replace('bg-indigo-600', 'bg-red-600');
            btn.disabled = false;
        }
    } catch {
        btn.textContent = 'Failed';
        btn.disabled = false;
    }
}
</script>
</body>
</html>
