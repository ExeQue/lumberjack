import { useSyncExternalStore } from 'react';

const KEY = 'lumberjack.sound';
const listeners = new Set<() => void>();

function read(): boolean {
    try {
        return window.localStorage.getItem(KEY) !== 'off';
    } catch {
        return true;
    }
}

/**
 * Whether a new error plays a sound: on unless this browser turned it off.
 */
export function useSoundSetting(): [boolean, (on: boolean) => void] {
    const on = useSyncExternalStore(
        (listener) => {
            listeners.add(listener);

            return () => listeners.delete(listener);
        },
        read,
        () => true,
    );

    const set = (value: boolean) => {
        try {
            window.localStorage.setItem(KEY, value ? 'on' : 'off');
        } catch {
            // Private windows may refuse; the setting just does not stick.
        }

        listeners.forEach((listener) => listener());
    };

    return [on, set];
}

/**
 * A short two-note chime, made on the spot so there is no sound file to load.
 * Browsers only play it once the page has had a click or key press.
 */
export function playChime(regression = false): void {
    try {
        const context = new AudioContext();
        const notes = regression ? [659.25, 523.25] : [880, 1174.66];

        notes.forEach((frequency, i) => {
            const oscillator = context.createOscillator();
            const gain = context.createGain();
            const start = context.currentTime + i * 0.14;

            oscillator.type = 'sine';
            oscillator.frequency.value = frequency;
            gain.gain.setValueAtTime(0.0001, start);
            gain.gain.exponentialRampToValueAtTime(0.18, start + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, start + 0.35);
            oscillator.connect(gain).connect(context.destination);
            oscillator.start(start);
            oscillator.stop(start + 0.4);
        });

        window.setTimeout(() => void context.close(), 1000);
    } catch {
        // No audio in this browser; the toast still shows.
    }
}
