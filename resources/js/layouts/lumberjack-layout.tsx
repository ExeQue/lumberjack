import type { ReactNode } from 'react';
import { FlashMessages } from '@/components/lj/flash';
import { NewIssueToasts } from '@/components/lj/new-issue-toasts';
import { Sidebar } from '@/components/lj/sidebar';

export default function LumberjackLayout({
    children,
}: {
    children: ReactNode;
}) {
    return (
        <div className="flex min-h-screen flex-wrap bg-ground font-sans text-[13px] text-ink">
            <div className="flex w-full lg:sticky lg:top-0 lg:h-screen lg:w-[232px] lg:flex-none lg:self-start lg:overflow-y-auto">
                <Sidebar />
            </div>
            <main className="flex min-w-0 flex-[999_1_560px] flex-col">
                {children}
            </main>
            <FlashMessages />
            <NewIssueToasts />
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
