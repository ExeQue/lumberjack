import { loadFont as loadSans } from '@remotion/google-fonts/IBMPlexSans';
import { loadFont as loadMono } from '@remotion/google-fonts/JetBrainsMono';

const sans = loadSans('normal', {
    weights: ['400', '500', '600', '700'],
    subsets: ['latin'],
});
const mono = loadMono('normal', {
    weights: ['400', '500', '700'],
    subsets: ['latin'],
});

export const FONT = sans.fontFamily;
export const MONO = mono.fontFamily;

/** The dark theme of the app (resources/css/app.css, `.dark`). */
export const C = {
    ground: '#0e1012',
    panel: '#161a1e',
    panel2: '#1c2126',
    rail: '#121518',
    line: '#262c32',
    lineStrong: '#333a41',
    ink: '#e7eaee',
    ink2: '#a3acb5',
    ink3: '#8a939c',
    amber: '#f0a93b',
    amberInk: '#1a1206',
    err: '#f2705f',
    errBg: '#2a1715',
    errLine: '#5a2c27',
    warn: '#e8b341',
    warnBg: '#2a2312',
    warnLine: '#5a4720',
    ok: '#47c08a',
    okBg: '#13261d',
    okLine: '#1e4a36',
    info: '#6aa5f5',
    infoBg: '#141f30',
    infoLine: '#22406a',
    violet: '#b28cf5',
} as const;

export type Tone = 'err' | 'warn' | 'ok' | 'info' | 'amber' | 'muted';

export const tone = (t: Tone) =>
    ({
        err: { fg: C.err, bg: C.errBg, line: C.errLine },
        warn: { fg: C.warn, bg: C.warnBg, line: C.warnLine },
        ok: { fg: C.ok, bg: C.okBg, line: C.okLine },
        info: { fg: C.info, bg: C.infoBg, line: C.infoLine },
        amber: { fg: C.amber, bg: '#2a200e', line: '#5a4316' },
        muted: { fg: C.ink2, bg: C.panel2, line: C.lineStrong },
    })[t];
