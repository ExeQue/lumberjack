/**
 * Where a paired window keeps a copy of its display token, in case its browser drops
 * the cookie: one per window (/tv/{slot}). Only ever sent in a request body, never in a
 * URL.
 */
export function displayTokenKey(slot: number): string {
    return `lumberjack.display-token.${slot}`;
}

/** Where a window of a TV asks, as the server hands them out. */
export type TvUrls = { claim: string; restore: string; heartbeat: string };
