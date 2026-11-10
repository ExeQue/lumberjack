import { TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import { CopyButton } from '@/components/lj/copy-text';
import { Button, Mono, Segmented } from '@/components/lj/ui';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useT } from '@/lib/i18n';

const clients = [
    'claude_code',
    'codex',
    'cursor',
    'vscode',
    'claude_desktop',
] as const;

type Client = (typeof clients)[number];

const env = 'LUMBERJACK_MCP_TOKEN';

const json = (value: unknown): string => JSON.stringify(value, null, 4);

/**
 * The setup for each client. Only Claude Desktop needs the token itself, in a file outside any
 * repository; the others read it from the environment or ask for it, so their config can be committed.
 */
function snippet(client: Client, url: string): string {
    switch (client) {
        case 'claude_code':
            return `claude mcp add --transport http --scope project --header 'Authorization: Bearer \${${env}}' lumberjack ${url}`;
        case 'codex':
            return `codex mcp add lumberjack --url ${url} --bearer-token-env-var ${env}`;
        case 'cursor':
            return json({
                mcpServers: {
                    lumberjack: {
                        url,
                        headers: { Authorization: `Bearer \${env:${env}}` },
                    },
                },
            });
        case 'vscode':
            return json({
                inputs: [
                    {
                        type: 'promptString',
                        id: 'lumberjack-token',
                        description: 'Lumberjack MCP token',
                        password: true,
                    },
                ],
                servers: {
                    lumberjack: {
                        type: 'http',
                        url,
                        headers: {
                            Authorization: 'Bearer ${input:lumberjack-token}',
                        },
                    },
                },
            });
        case 'claude_desktop':
            return json({
                mcpServers: {
                    lumberjack: {
                        command: 'npx',
                        args: [
                            'mcp-remote',
                            url,
                            '--header',
                            'Authorization:${AUTH_HEADER}',
                        ],
                        env: { AUTH_HEADER: 'Bearer lj_mcp_...' },
                    },
                },
            });
    }
}

/**
 * How to connect an MCP client, per client, with the token kept out of anything committed.
 */
export function McpSetup({ url }: { url: string }) {
    const t = useT();
    const [client, setClient] = useState<Client>('claude_code');
    const code = snippet(client, url);
    const exported = `export ${env}=lj_mcp_...`;

    return (
        <div className="flex min-w-0 flex-col gap-3">
            <p
                role="note"
                className="flex gap-2 rounded-md border border-warn/40 bg-warn/10 p-3 text-xs text-ink-2"
            >
                <TriangleAlert
                    className="mt-0.5 size-3.5 shrink-0 text-warn"
                    aria-hidden="true"
                />
                <span>{t('team.tokens.connect.warning')}</span>
            </p>
            <div className="overflow-x-auto">
                <Segmented
                    label={t('team.tokens.connect.client')}
                    value={client}
                    options={clients.map((value) => ({
                        value,
                        label: t(`team.tokens.connect.clients.${value}.name`),
                    }))}
                    onChange={setClient}
                />
            </div>
            <p className="text-xs text-ink-3">
                {t(`team.tokens.connect.clients.${client}.where`)}
            </p>
            <div className="flex items-start gap-2">
                <pre
                    tabIndex={0}
                    aria-label={t('team.tokens.connect.copy_setup')}
                    className="min-w-0 flex-1 overflow-x-auto rounded-md bg-panel-2 p-3 font-mono text-xs text-ink focus-visible:outline focus-visible:outline-amber"
                >
                    {code}
                </pre>
                <CopyButton
                    text={code}
                    label={t('team.tokens.connect.copy_setup')}
                />
            </div>
            {client !== 'vscode' && client !== 'claude_desktop' && (
                <>
                    <p className="text-xs text-ink-3">
                        {t('team.tokens.connect.env', { env })}
                    </p>
                    <div className="flex items-start gap-2">
                        <Mono className="min-w-0 flex-1 overflow-x-auto rounded-md bg-panel-2 p-3 text-xs text-ink">
                            {exported}
                        </Mono>
                        <CopyButton
                            text={exported}
                            label={t('team.tokens.connect.copy_env')}
                        />
                    </div>
                </>
            )}
            <p className="text-xs text-ink-3">
                {t('team.tokens.connect.several')}
            </p>
        </div>
    );
}

/**
 * A button that opens the client setup in a dialog.
 */
export function McpSetupDialog({ url }: { url: string }) {
    const t = useT();
    const [open, setOpen] = useState(false);

    return (
        <>
            <Button type="button" onClick={() => setOpen(true)}>
                {t('team.tokens.connect.title')}
            </Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>
                            {t('team.tokens.connect.title')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('team.tokens.connect.description', { url })}
                        </DialogDescription>
                    </DialogHeader>
                    <McpSetup url={url} />
                    <DialogFooter>
                        <Button
                            variant="primary"
                            onClick={() => setOpen(false)}
                        >
                            {t('common.done')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
