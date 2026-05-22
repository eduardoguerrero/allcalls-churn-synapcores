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
    </div>
</header>

<main class="max-w-7xl mx-auto px-6 py-8">

    {{-- Search --}}
    <form method="GET" action="{{ route('dashboard') }}" class="mb-6 flex gap-2">
        <div class="relative flex-1 max-w-sm">
            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none"
                 fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M21 21l-4.35-4.35M17 11A6 6 0 1 1 5 11a6 6 0 0 1 12 0z"/>
            </svg>
            <input
                type="text"
                name="search"
                value="{{ $search ?? '' }}"
                placeholder="Search by tier or member ID…"
                class="w-full pl-9 pr-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white">
        </div>
        <button type="submit"
                class="px-4 py-2 text-sm font-medium bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 active:scale-95 transition-all">
            Search
        </button>
        @if ($search)
            <a href="{{ route('dashboard') }}"
               class="px-4 py-2 text-sm font-medium text-gray-600 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-all">
                Clear
            </a>
        @endif
    </form>

    @if ($members->isEmpty())
        {{-- Empty state --}}
        <div class="flex flex-col items-center justify-center py-24 text-center">
            <div class="w-14 h-14 rounded-full bg-gray-100 flex items-center justify-center mb-4">
                <svg class="w-7 h-7 text-gray-400" fill="none" stroke="currentColor" stroke-width="1.5"
                     viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/>
                </svg>
            </div>
            <span
                class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-blue-50 text-blue-700 ring-1 ring-blue-200 mb-3">
                <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd"
                          d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a.75.75 0 000 1.5h.253a.25.25 0 01.244.304l-.459 2.066A1.75 1.75 0 0010.747 15H11a.75.75 0 000-1.5h-.253a.25.25 0 01-.244-.304l.459-2.066A1.75 1.75 0 009.253 9H9z"
                          clip-rule="evenodd"/>
                </svg>
                @if ($search) No results @else No predictions available @endif
            </span>
            <p class="text-sm text-gray-500 max-w-xs">
                @if ($search)
                    Try a different tier name (Gold, Platinum) or a member ID.
                @else
                    Seed the database and train the model to see at-risk members here.
                @endif
            </p>
        </div>

    @else
        {{-- Summary cards --}}
        <div class="grid grid-cols-3 gap-4 mb-6">
            <div class="bg-white rounded-xl border border-gray-200 px-5 py-4">
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Members shown</p>
                <p class="text-2xl font-bold text-gray-900 mt-1">{{ $paginator->total() }}</p>
                <p class="text-xs text-gray-400 mt-0.5">Page {{ $paginator->currentPage() }}
                    of {{ $paginator->lastPage() }} · Gold &amp; Platinum only</p>
            </div>
            <div class="bg-white rounded-xl border border-gray-200 px-5 py-4">
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Avg. churn probability</p>
                <p class="text-2xl font-bold text-gray-900 mt-1">{{ $avg }}%</p>
                <p class="text-xs text-gray-400 mt-0.5">Across current page</p>
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
                    <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Tier
                    </th>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Tenure
                    </th>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Visits
                        30d
                    </th>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Spend
                        30d
                    </th>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">
                        P(Churn)
                    </th>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Action
                    </th>
                </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                @foreach ($members as $member)
                    @php
                        $p = $member->churnProbability;
                        $barColor  = $p >= 0.7 ? 'bg-red-500' : ($p >= 0.4 ? 'bg-amber-400' : 'bg-emerald-400');
                        $textColor = $p >= 0.7 ? 'text-red-600' : ($p >= 0.4 ? 'text-amber-600' : 'text-emerald-600');
                        $badgeColor = match($member->tier) {
                            'Platinum' => 'bg-purple-50 text-purple-700 ring-1 ring-purple-200',
                            'Gold'     => 'bg-amber-50 text-amber-700 ring-1 ring-amber-200',
                            default    => 'bg-gray-100 text-gray-600',
                        };
                    @endphp
                    <tr class="hover:bg-gray-50 transition-colors" id="row-{{ $member->id }}">
                        <td class="px-5 py-3.5 text-gray-400 font-mono text-xs">#{{ $member->id }}</td>
                        <td class="px-5 py-3.5">
                                <span
                                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold {{ $badgeColor }}">
                                    {{ $member->tier }}
                                </span>
                        </td>
                        <td class="px-5 py-3.5 text-gray-700">{{ $member->tenureMonths }} mo.</td>
                        <td class="px-5 py-3.5 text-gray-700">{{ $member->visits30d }}</td>
                        <td class="px-5 py-3.5 text-gray-700">${{ number_format($member->spend30d, 2) }}</td>
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

        {{-- Pagination --}}
        @if ($paginator->hasPages())
            <div class="mt-4">
                {{ $paginator->appends(['search' => $search])->links() }}
            </div>
        @endif
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
