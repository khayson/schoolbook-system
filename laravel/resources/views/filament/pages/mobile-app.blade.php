@php
    /** @var bool $apkReady */
    /** @var string|null $apkSizeLabel */
    /** @var string|null $apkUpdatedLabel */
    /** @var string $hostUrl */
    /** @var string|null $signedDownloadUrl */
    /** @var bool $hostPinConfigured */
@endphp

<div class="space-y-6">
    {{-- Hero status --}}
    <div
        class="relative overflow-hidden rounded-2xl border border-gray-200 dark:border-white/10"
        style="background: linear-gradient(135deg, #111111 0%, #1a1a1a 55%, #2a320f 100%);"
    >
        <div
            class="pointer-events-none absolute -right-16 -top-16 h-56 w-56 rounded-full opacity-30"
            style="background: radial-gradient(circle, #C8F54A 0%, transparent 70%);"
        ></div>
        <div class="relative flex flex-col gap-6 p-6 sm:flex-row sm:items-center sm:justify-between sm:p-8">
            <div class="max-w-xl space-y-3">
                <p class="text-xs font-semibold uppercase tracking-[0.18em]" style="color: #C8F54A;">
                    Schoolbook · Android
                </p>
                <h2 class="text-2xl font-bold tracking-tight text-white sm:text-3xl">
                    @if ($apkReady)
                        App ready to install
                    @else
                        No build on this server yet
                    @endif
                </h2>
                <p class="text-sm leading-relaxed text-white/70">
                    @if ($apkReady)
                        Download the APK, send it to phones (WhatsApp, Drive, or USB), then point each device at this shop’s server.
                    @else
                        Upload a release build under <span class="font-medium text-white">Replace build</span> below. Cashiers use Download / the share link — not that box.
                    @endif
                </p>
                @if ($apkReady)
                    <div class="flex flex-wrap items-center gap-3 pt-1 text-sm text-white/60">
                        <span class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold text-black" style="background: #C8F54A;">
                            schoolbook-release.apk
                        </span>
                        @if ($apkSizeLabel)
                            <span>{{ $apkSizeLabel }}</span>
                        @endif
                        @if ($apkUpdatedLabel)
                            <span>· Updated {{ $apkUpdatedLabel }}</span>
                        @endif
                    </div>
                @endif
            </div>

            @if ($apkReady)
                <div class="flex shrink-0 flex-col gap-2 sm:items-end">
                    <x-filament::button
                        tag="a"
                        :href="route('admin.mobile-app.download-apk')"
                        icon="heroicon-o-arrow-down-tray"
                        size="xl"
                        class="!px-6"
                    >
                        Download APK
                    </x-filament::button>
                    <p class="text-xs text-white/50">Opens a normal browser download</p>
                </div>
            @endif
        </div>
    </div>

    @if ($apkReady && $signedDownloadUrl)
        <x-filament::section>
            <x-slot name="heading">Share without Filament login</x-slot>
            <x-slot name="description">
                Anyone with this link can download the APK for 7 days. Paste it into WhatsApp or email.
            </x-slot>

            <div class="flex flex-col gap-3 sm:flex-row sm:items-stretch">
                <input
                    type="text"
                    readonly
                    value="{{ $signedDownloadUrl }}"
                    class="fi-input block w-full rounded-xl border-none bg-gray-50 px-3 py-2.5 text-sm shadow-sm ring-1 ring-gray-950/10 dark:bg-white/5 dark:ring-white/20"
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
        </x-filament::section>
    @endif

    <div class="grid gap-6 lg:grid-cols-2">
        <x-filament::section>
            <x-slot name="heading">After install</x-slot>
            <x-slot name="description">Three steps on each phone.</x-slot>

            <ol class="space-y-4">
                <li class="flex gap-3">
                    <span
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-bold text-black"
                        style="background: #C8F54A;"
                    >1</span>
                    <div>
                        <p class="text-sm font-semibold text-gray-950 dark:text-white">Install the APK</p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Android may ask to allow installs from this source.</p>
                    </div>
                </li>
                <li class="flex gap-3">
                    <span
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-bold text-black"
                        style="background: #C8F54A;"
                    >2</span>
                    <div>
                        <p class="text-sm font-semibold text-gray-950 dark:text-white">Unlock Host setup</p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            Open the app → <strong>More</strong> → long-press the title (or tap it 7 times), then enter the setup PIN.
                        </p>
                    </div>
                </li>
                <li class="flex gap-3">
                    <span
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-bold text-black"
                        style="background: #C8F54A;"
                    >3</span>
                    <div>
                        <p class="text-sm font-semibold text-gray-950 dark:text-white">Paste this shop’s host</p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Save, then sign in with a user from System → Users.</p>
                    </div>
                </li>
            </ol>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">This shop’s host</x-slot>
            <x-slot name="description">
                Paste into the phone after unlocking Host. Cashier login is a user you create under System → Users.
            </x-slot>

            <div class="flex flex-col gap-2 sm:flex-row">
                <input
                    type="text"
                    readonly
                    value="{{ $hostUrl }}"
                    class="fi-input block w-full rounded-xl border-none bg-gray-50 px-3 py-2.5 font-mono text-sm shadow-sm ring-1 ring-gray-950/10 dark:bg-white/5 dark:ring-white/20"
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

            <div
                class="mt-5 flex items-start gap-3 rounded-xl border px-4 py-3
                    {{ $hostPinConfigured
                        ? 'border-emerald-200 bg-emerald-50 dark:border-emerald-500/30 dark:bg-emerald-500/10'
                        : 'border-amber-200 bg-amber-50 dark:border-amber-500/30 dark:bg-amber-500/10' }}"
            >
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-gray-950 dark:text-white">
                        Host unlock PIN
                        @if ($hostPinConfigured)
                            <span class="ms-2 inline-flex rounded-full bg-emerald-600 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white">On server</span>
                        @else
                            <span class="ms-2 inline-flex rounded-full bg-amber-600 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white">APK default</span>
                        @endif
                    </p>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                        @if ($hostPinConfigured)
                            Set on this server — phones check it over the network. Reset anytime in the form below (no APK rebuild).
                        @else
                            Still the APK’s built-in PIN until you set one in the form below.
                        @endif
                    </p>
                </div>
            </div>
        </x-filament::section>
    </div>
</div>
