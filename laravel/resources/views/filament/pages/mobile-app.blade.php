@php
    /** @var bool $apkReady */
    /** @var string|null $apkSizeLabel */
    /** @var string|null $apkUpdatedLabel */
    /** @var string $hostUrl */
    /** @var string|null $signedDownloadUrl */
    /** @var bool $hostPinConfigured */
@endphp

<div class="space-y-6">
    <x-filament::section>
        <x-slot name="heading">Android app for cashiers</x-slot>
        <x-slot name="description">
            Download the APK here, send it to phones (WhatsApp, Drive, USB), then point each phone at this shop’s server.
        </x-slot>

        @if ($apkReady)
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Ready to install</p>
                    <p class="text-lg font-semibold text-gray-950 dark:text-white">
                        schoolbook-release.apk
                        @if ($apkSizeLabel)
                            <span class="font-normal text-gray-500 dark:text-gray-400">· {{ $apkSizeLabel }}</span>
                        @endif
                    </p>
                    @if ($apkUpdatedLabel)
                        <p class="text-sm text-gray-500 dark:text-gray-400">Updated {{ $apkUpdatedLabel }}</p>
                    @endif
                </div>

                <div class="flex flex-wrap gap-2">
                    <x-filament::button
                        tag="a"
                        :href="route('admin.mobile-app.download-apk')"
                        icon="heroicon-o-arrow-down-tray"
                        size="lg"
                    >
                        Download APK
                    </x-filament::button>
                </div>
            </div>

            @if ($signedDownloadUrl)
                <div class="mt-6 rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
                    <p class="mb-2 text-sm font-medium text-gray-950 dark:text-white">Share link (no Filament login needed)</p>
                    <p class="mb-3 text-sm text-gray-500 dark:text-gray-400">
                        Valid for 7 days. Anyone with the link can download the APK.
                    </p>
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <input
                            type="text"
                            readonly
                            value="{{ $signedDownloadUrl }}"
                            class="fi-input block w-full rounded-lg border-none bg-white px-3 py-2 text-sm shadow-sm ring-1 ring-gray-950/10 dark:bg-white/5 dark:ring-white/20"
                            onclick="this.select()"
                        />
                        <x-filament::button
                            color="gray"
                            type="button"
                            x-data
                            x-on:click="
                                navigator.clipboard.writeText(@js($signedDownloadUrl));
                                $tooltip('Copied', { timeout: 1500 });
                            "
                        >
                            Copy link
                        </x-filament::button>
                    </div>
                </div>
            @endif
        @else
            <p class="text-sm text-gray-600 dark:text-gray-300">
                No APK on this server yet. Upload a release build in <strong>Replace build</strong> below.
            </p>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">After install — connect this shop</x-slot>
        <ol class="list-decimal space-y-2 ps-5 text-sm text-gray-700 dark:text-gray-200">
            <li>Install the APK (Android may ask to allow installs from this source).</li>
            <li>Open the app → <strong>More</strong> → long-press the title (or tap it 7 times).</li>
            <li>Enter the setup PIN, then paste this host and Save:</li>
        </ol>
        <div class="mt-4 flex flex-col gap-2 sm:flex-row">
            <input
                type="text"
                readonly
                value="{{ $hostUrl }}"
                class="fi-input block w-full rounded-lg border-none bg-white px-3 py-2 text-sm shadow-sm ring-1 ring-gray-950/10 dark:bg-white/5 dark:ring-white/20"
                onclick="this.select()"
            />
            <x-filament::button
                color="gray"
                type="button"
                x-data
                x-on:click="
                    navigator.clipboard.writeText(@js($hostUrl));
                    $tooltip('Copied', { timeout: 1500 });
                "
            >
                Copy host
            </x-filament::button>
        </div>
        <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
            Cashier login is a user you create under <strong>System → Users</strong> (active owner accounts).
            Host unlock PIN:
            @if ($hostPinConfigured)
                set on this server (reset below) — phones check it over the network.
            @else
                still the APK’s built-in PIN until you set one below.
            @endif
        </p>
    </x-filament::section>
</div>
