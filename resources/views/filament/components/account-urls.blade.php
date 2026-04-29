<div class="space-y-4">
    <div class="rounded-lg bg-gray-50 dark:bg-gray-800 p-4">
        <div class="flex items-center justify-between mb-2">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">M3U Playlist URL</h3>
            <button
                type="button"
                onclick="navigator.clipboard.writeText('{{ $m3u_url }}');
                         this.querySelector('span').textContent = 'Copied!';
                         setTimeout(() => this.querySelector('span').textContent = 'Copy', 2000)"
                class="text-xs px-3 py-1 bg-blue-500 text-white rounded hover:bg-blue-600 transition"
            >
                <span>Copy</span>
            </button>
        </div>
        <code class="text-xs block p-3 bg-white dark:bg-gray-900 rounded border border-gray-200 dark:border-gray-700 break-all">
            {{ $m3u_url }}
        </code>
    </div>

    <div class="rounded-lg bg-gray-50 dark:bg-gray-800 p-4">
        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">Xtream Codes API</h3>

        <div class="space-y-3">
            <div>
                <label class="text-xs text-gray-600 dark:text-gray-400">Server URL</label>
                <div class="flex items-center gap-2 mt-1">
                    <code class="text-xs flex-1 p-2 bg-white dark:bg-gray-900 rounded border border-gray-200 dark:border-gray-700">
                        {{ $xtream_url }}
                    </code>
                    <button
                        type="button"
                        onclick="navigator.clipboard.writeText('{{ $xtream_url }}');
                                 this.textContent = '✓';
                                 setTimeout(() => this.textContent = 'Copy', 2000)"
                        class="text-xs px-2 py-1 bg-gray-200 dark:bg-gray-700 rounded hover:bg-gray-300 dark:hover:bg-gray-600 transition"
                    >
                        Copy
                    </button>
                </div>
            </div>

            <div>
                <label class="text-xs text-gray-600 dark:text-gray-400">Username</label>
                <div class="flex items-center gap-2 mt-1">
                    <code class="text-xs flex-1 p-2 bg-white dark:bg-gray-900 rounded border border-gray-200 dark:border-gray-700">
                        {{ $username }}
                    </code>
                    <button
                        type="button"
                        onclick="navigator.clipboard.writeText('{{ $username }}');
                                 this.textContent = '✓';
                                 setTimeout(() => this.textContent = 'Copy', 2000)"
                        class="text-xs px-2 py-1 bg-gray-200 dark:bg-gray-700 rounded hover:bg-gray-300 dark:hover:bg-gray-600 transition"
                    >
                        Copy
                    </button>
                </div>
            </div>

            <div>
                <label class="text-xs text-gray-600 dark:text-gray-400">Password</label>
                <div class="flex items-center gap-2 mt-1">
                    <code class="text-xs flex-1 p-2 bg-white dark:bg-gray-900 rounded border border-gray-200 dark:border-gray-700">
                        {{ $password }}
                    </code>
                    <button
                        type="button"
                        onclick="navigator.clipboard.writeText('{{ $password }}');
                                 this.textContent = '✓';
                                 setTimeout(() => this.textContent = 'Copy', 2000)"
                        class="text-xs px-2 py-1 bg-gray-200 dark:bg-gray-700 rounded hover:bg-gray-300 dark:hover:bg-gray-600 transition"
                    >
                        Copy
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
