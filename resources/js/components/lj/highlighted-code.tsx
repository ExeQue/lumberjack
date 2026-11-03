import type { CSSProperties } from 'react';
import { useHighlightedLines } from '@/lib/highlight';
import type { CodeLanguage } from '@/lib/highlight';

/**
 * Code in its colors, inline: plain text until the highlighter has loaded. The element
 * keeps the classes given, so it truncates or clamps as before.
 */
export function HighlightedCode({
    code,
    language,
    className,
    title,
}: {
    code: string;
    language: CodeLanguage;
    className?: string;
    title?: string;
}) {
    const lines = code.split('\n');
    const tokens = useHighlightedLines(lines, language);

    return (
        <code className={`lj-code ${className ?? ''}`} title={title}>
            {tokens
                ? tokens.map((line, i) => (
                      <span key={i}>
                          {i > 0 && '\n'}
                          {line.map((token, j) => (
                              <span
                                  key={j}
                                  style={token.htmlStyle as CSSProperties}
                              >
                                  {token.content}
                              </span>
                          ))}
                      </span>
                  ))
                : code}
        </code>
    );
}
