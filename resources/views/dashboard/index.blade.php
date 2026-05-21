<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Churn Predictor — At-Risk Members</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <meta name="csrf-token" content="{{ csrf_token() }}">
</head>
<body class="bg-gray-50 text-gray-800">

<div class="max-w-7xl mx-auto px-4 py-8">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Loyalty Churn Predictor</h1>
        <p class="text-sm text-gray-500 mt-1">
            Top at-risk <strong>Gold</strong> &amp; <strong>Platinum</strong> members ranked by predicted churn probability.
        </p>
    </div>

    @if ($members->isEmpty())
        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-6 text-center">
            <p class="text-yellow-800 font-medium">No predictions available yet.</p>
            <p class="text-yellow-600 text-sm mt-1">
                Run <code class="bg-yellow-100 px-1 rounded">php artisan synapcores:seed</code>
                then <code class="bg-yellow-100 px-1 rounded">php artisan synapcores:train</code> to generate predictions.
            </p>
        </div>
    @else
        <div class="bg-white shadow-sm rounded-lg overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-100">
                    <tr>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">ID</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Tier</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Tenure (mo.)</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Visits 30d</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Spend 30d</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">P(Churn)</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($members as $member)
                        @php
                            $p = $member->churn_probability ?? 0;
                            $barColor = $p >= 0.7 ? 'bg-red-500' : ($p >= 0.4 ? 'bg-yellow-400' : 'bg-green-400');
                            $badgeColor = match($member->tier) {
                                'Platinum' => 'bg-purple-100 text-purple-800',
                                'Gold'     => 'bg-yellow-100 text-yellow-800',
                                default    => 'bg-gray-100 text-gray-700',
                            };
                        @endphp
                        <tr class="hover:bg-gray-50" id="row-{{ $member->id }}">
                            <td class="px-4 py-3 text-gray-500">#{{ $member->id }}</td>
                            <td class="px-4 py-3">
                                <span class="inline-block px-2 py-0.5 rounded text-xs font-semibold {{ $badgeColor }}">
                                    {{ $member->tier }}
                                </span>
                            </td>
                            <td class="px-4 py-3">{{ $member->tenure_months }}</td>
                            <td class="px-4 py-3">{{ $member->visits_30d }}</td>
                            <td class="px-4 py-3">${{ number_format($member->spend_30d, 2) }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <div class="w-28 bg-gray-200 rounded-full h-2">
                                        <div class="{{ $barColor }} h-2 rounded-full"
                                             style="width: {{ round($p * 100) }}%"></div>
                                    </div>
                                    <span class="text-xs font-mono">{{ number_format($p * 100, 1) }}%</span>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <button
                                    onclick="sendOffer({{ $member->id }}, this)"
                                    class="px-3 py-1 text-xs bg-indigo-600 text-white rounded hover:bg-indigo-700 transition">
                                    Send Offer
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="text-xs text-gray-400 mt-2">Showing {{ $members->count() }} of top 50 at-risk Gold/Platinum members.</p>
    @endif
</div>

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
            btn.textContent = 'Offer Sent ✓';
            btn.classList.replace('bg-indigo-600', 'bg-green-600');
        } else {
            btn.textContent = 'Error';
            btn.classList.replace('bg-indigo-600', 'bg-red-600');
            btn.disabled = false;
        }
    } catch {
        btn.textContent = 'Error';
        btn.disabled = false;
    }
}
</script>
</body>
</html>
