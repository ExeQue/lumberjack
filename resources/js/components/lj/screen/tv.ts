/**
 * Where a paired TV keeps a copy of its display token, in case its browser drops the
 * cookie. Only ever sent in a request body, never in a URL.
 */
export const DISPLAY_TOKEN_KEY = 'lumberjack.display-token';
