@props(['title'])
<x-layouts.base :title="$title">
    <div class="grid min-h-screen lg:grid-cols-2">
        <div class="relative flex flex-col px-6 py-8 sm:px-12">
            <a href="{{ route('home') }}"><x-logo/></a>
            <div class="mx-auto flex w-full max-w-sm flex-1 flex-col justify-center py-12">
                {{ $slot }}
            </div>
            <p class="text-xs text-ink-500">© {{ date('Y') }} MusicDesk Technologies</p>
        </div>
        <div class="relative hidden overflow-hidden border-l hairline lg:block">
            <div class="absolute inset-0" style="background: radial-gradient(70% 60% at 70% 20%, rgb(255 63 210 / .35), transparent 60%), radial-gradient(70% 70% at 20% 80%, rgb(124 92 255 / .5), transparent 60%), #0b0b12"></div>
            <div class="absolute inset-0 grid-bg opacity-70 [mask-image:none]"></div>
            {{-- vinyl --}}
            <div class="absolute left-1/2 top-[42%] size-[440px] -translate-x-1/2 -translate-y-1/2">
                <div class="size-full animate-spin-slow rounded-full shadow-[0_40px_120px_-20px_rgb(0_0_0/.9)]"
                     style="background: repeating-radial-gradient(circle at center, #0c0c12 0 2px, #15151f 2px 4px); ">
                    <div class="absolute inset-0 rounded-full" style="background: conic-gradient(from 30deg, transparent 0 20%, rgb(255 255 255 / .09) 25%, transparent 30% 70%, rgb(255 255 255 / .06) 75%, transparent 80%)"></div>
                    <div class="absolute left-1/2 top-1/2 grid size-36 -translate-x-1/2 -translate-y-1/2 place-items-center rounded-full" style="background: conic-gradient(from 210deg, #7c5cff, #ff3fd2, #ffb547, #7c5cff)">
                        <div class="size-3 rounded-full bg-ink-950"></div>
                    </div>
                </div>
            </div>
            <div class="absolute inset-x-12 bottom-12">
                <blockquote class="font-serif text-3xl italic leading-snug text-white">“We launched our own branded platform in nine days. Our artists only ever see our name.”</blockquote>
                <div class="mt-4 text-sm text-ink-300">Emma Clarke, Northlight Distribution</div>
            </div>
        </div>
    </div>
</x-layouts.base>
