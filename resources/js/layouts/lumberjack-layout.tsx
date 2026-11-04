import { usePage } from '@inertiajs/react';
import { Menu } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { CommandPalette } from '@/components/lj/command-palette';
import { FlashMessages } from '@/components/lj/flash';
import { LiveSidebar } from '@/components/lj/live-sidebar';
import { LogMark } from '@/components/lj/logo';
import { NewIssueToasts } from '@/components/lj/new-issue-toasts';
import { Sidebar } from '@/components/lj/sidebar';
import { Sheet, SheetContent, SheetTitle } from '@/components/ui/sheet';
import { useT } from '@/lib/i18n';

export default function LumberjackLayout({
    children,
}: {
    children: ReactNode;
}) {
    const t = useT();
    const url = usePage().url;
    const [menuOpen, setMenuOpen] = useState(false);

    // A link in the menu navigates; the menu then closes on its own.
    useEffect(() => setMenuOpen(false), [url]);

    return (
        <div className="flex min-h-screen flex-col bg-ground font-sans text-[13px] text-ink lg:flex-row">
            {/* Desktop: the sidebar beside the page. */}
            <div className="hidden lg:sticky lg:top-0 lg:flex lg:h-screen lg:w-[232px] lg:flex-none lg:[scrollbar-gutter:stable] lg:self-start lg:overflow-y-auto lg:border-r lg:border-line lg:bg-rail">
                <Sidebar />
            </div>

            {/* Phone and tablet: a bar with the menu behind a button, so the page starts at the top. */}
            <header className="sticky top-0 z-30 flex h-12 items-center gap-3 border-b border-line bg-rail px-3 lg:hidden">
                <button
                    type="button"
                    onClick={() => setMenuOpen(true)}
                    aria-label={t('common.nav.open')}
                    aria-expanded={menuOpen}
                    className="inline-flex size-9 items-center justify-center rounded-md text-ink-2 hover:bg-panel-2 hover:text-ink"
                >
                    <Menu className="size-5" aria-hidden="true" />
                </button>
                <span className="flex items-center gap-2 text-ink">
                    <LogMark />
                    <span className="text-[15px] font-semibold tracking-tight">
                        Lumberjack
                    </span>
                </span>
            </header>
            <CommandPalette />
            <Sheet open={menuOpen} onOpenChange={setMenuOpen}>
                <SheetContent
                    side="left"
                    className="w-[260px] max-w-[85vw] [scrollbar-gutter:stable] gap-0 overflow-y-auto border-line bg-rail p-0"
                >
                    <SheetTitle className="sr-only">
                        {t('common.nav.label')}
                    </SheetTitle>
                    <Sidebar />
                </SheetContent>
            </Sheet>

            <main className="flex min-w-0 flex-1 flex-col">{children}</main>
            <FlashMessages />
            <NewIssueToasts />
            <LiveSidebar />
        </div>
    );
}

export function PageBody({
    children,
    wide = false,
}: {
    children: ReactNode;
    wide?: boolean;
}) {
    return (
        <div
            className={`flex w-full flex-col gap-5 px-4 pt-6 pb-10 sm:px-7 ${wide ? 'max-w-[1400px]' : 'max-w-[1200px]'}`}
        >
            {children}
        </div>
    );
}
