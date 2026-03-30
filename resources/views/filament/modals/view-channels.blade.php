<div class="space-y-4">
    @if($channels->count() > 0)
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Channel Name</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Category</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Stream URL</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200 dark:bg-gray-900 dark:divide-gray-700">
                    @foreach($channels as $channel)
                        <tr>
                            <td class="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">
                                {{ $channel->name }}
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">
                                {{ $channel->category ?? 'N/A' }}
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 truncate max-w-xs" title="{{ $channel->stream_url }}">
                                {{ Str::limit($channel->stream_url, 50) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if($channels->count() >= 100)
            <p class="text-sm text-gray-500 dark:text-gray-400">Showing first 100 channels</p>
        @endif
    @else
        <p class="text-gray-500 dark:text-gray-400">No channels available for this source.</p>
    @endif
</div>
